<?php

namespace app\controller\sicbo;

use app\BaseController;
use think\facade\Db;
use think\facade\Cache;
use think\exception\ValidateException;

/**
 * 骰宝游戏主控制器 - 修复版
 * 处理核心游戏逻辑、游戏状态管理、开奖流程控制
 */
class SicboGameController extends BaseController
{
    /**
     * 获取台桌游戏信息
     * 路由: GET /sicbo/game/table-info
     */
    public function getTableInfo()
    {
        $tableId = $this->request->param('table_id/d', 0);
        
        if ($tableId <= 0) {
            return json(['code' => 400, 'message' => '台桌ID无效']);
        }

        try {
            // 获取台桌基础信息 - 直接使用Db类查询，避免模型依赖问题
            $table = Db::name('dianji_table')
                ->where('id', $tableId)
                ->where('game_type', 9) // 骰宝游戏类型
                ->find();
            
            if (!$table) {
                return json(['code' => 404, 'message' => '台桌不存在']);
            }

            // 获取台桌状态信息
            $tableInfo = [
                'table_id' => $table['id'],
                'table_name' => $table['table_title'],
                'status' => $table['status'],
                'run_status' => $table['run_status'],
                'game_config' => $table['game_config'] ? json_decode($table['game_config'], true) : [],
            ];

            // 计算倒计时
            if ($table['run_status'] == 1) { // 投注中
                $endTime = $table['start_time'] + $table['countdown_time'];
                $tableInfo['countdown'] = max(0, $endTime - time());
            } else {
                $tableInfo['countdown'] = 0;
            }

            // 获取当前游戏局号
            $currentGame = $this->getCurrentGame($tableId);
            $tableInfo['current_game'] = $currentGame;

            // 获取最新开奖结果
            $latestResult = $this->getLastGame($tableId);
            $tableInfo['latest_result'] = $latestResult;

            // 获取今日统计
            $todayStats = $this->calculateTodayStats($tableId);
            $tableInfo['today_stats'] = $todayStats;

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => $tableInfo
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500, 
                'message' => '获取台桌信息失败：' . $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 获取游戏历史记录 - 修复版
     * 路由: GET /sicbo/game/history
     */
    public function getGameHistory()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $limit = $this->request->param('limit/d', 20);
        
        // 参数验证
        if ($tableId <= 0) {
            return json(['code' => 400, 'message' => '台桌ID无效']);
        }
        
        if ($limit <= 0 || $limit > 100) {
            $limit = 20; // 默认限制
        }

        try {
            // 直接使用Db查询，避免模型依赖问题
            $history = Db::name('sicbo_game_results')
                ->where('table_id', $tableId)
                ->where('status', 1) // 只获取有效结果
                ->order('id desc')
                ->limit($limit)
                ->select();

            // 格式化历史数据
            $formattedHistory = [];
            foreach ($history as $result) {
                $formattedHistory[] = [
                    'game_number' => $result['game_number'],
                    'round_number' => $result['round_number'],
                    'dice1' => (int)$result['dice1'],
                    'dice2' => (int)$result['dice2'],
                    'dice3' => (int)$result['dice3'],
                    'total_points' => (int)$result['total_points'],
                    'is_big' => (bool)$result['is_big'],
                    'is_odd' => (bool)($result['total_points'] % 2 == 1),
                    'has_triple' => (bool)$result['has_triple'],
                    'triple_number' => $result['triple_number'] ? (int)$result['triple_number'] : null,
                    'has_pair' => (bool)$result['has_pair'],
                    'winning_bets' => $result['winning_bets'] ? json_decode($result['winning_bets'], true) : [],
                    'created_at' => $result['created_at'] ?? date('Y-m-d H:i:s', $result['created_at']),
                ];
            }

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'table_id' => $tableId,
                    'history' => $formattedHistory,
                    'count' => count($formattedHistory),
                    'limit' => $limit
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500, 
                'message' => '获取游戏历史失败：' . $e->getMessage(),
                'debug' => [
                    'table_id' => $tableId,
                    'limit' => $limit,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'sql_error' => $e->getMessage()
                ]
            ]);
        }
    }

    /**
     * 获取游戏统计数据 - 修复版
     * 路由: GET /sicbo/game/statistics
     */
    public function getStatistics()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $type = $this->request->param('type', 'daily');
        
        if ($tableId <= 0) {
            return json(['code' => 400, 'message' => '台桌ID无效']);
        }

        try {
            $statistics = [];

            switch ($type) {
                case 'daily':
                    // 今日统计
                    $statistics['today'] = $this->calculateTodayStats($tableId);
                    $statistics['win_rates'] = $this->getWinRateStats($tableId, 'today');
                    break;
                    
                case 'weekly':
                    // 本周统计
                    $statistics['week'] = $this->getWinRateStats($tableId, 'week');
                    $statistics['hot_numbers'] = $this->getHotNumbers($tableId, 7);
                    break;
                    
                case 'trend':
                    // 趋势分析
                    $startDate = date('Y-m-d', strtotime('-7 days'));
                    $endDate = date('Y-m-d');
                    $statistics['trend'] = $this->getStatsTrend($tableId, $startDate, $endDate);
                    break;
            }

            // 总点数分布
            $statistics['total_distribution'] = $this->getTotalDistributionPercent($tableId, $type);

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500, 
                'message' => '获取统计数据失败：' . $e->getMessage(),
                'debug' => [
                    'table_id' => $tableId,
                    'type' => $type,
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 开始新游戏局 - 修复版
     */
    public function startNewGame()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $bettingTime = $this->request->param('betting_time/d', 30);
        
        if ($tableId <= 0) {
            return json(['code' => 400, 'message' => '台桌ID无效']);
        }
        
        if ($bettingTime < 10 || $bettingTime > 120) {
            return json(['code' => 400, 'message' => '投注时间必须在10-120秒之间']);
        }

        try {
            // 检查台桌状态
            $table = Db::name('dianji_table')->where('id', $tableId)->find();
            if (!$table || $table['game_type'] != 9) {
                return json(['code' => 404, 'message' => '台桌不存在或游戏类型错误']);
            }
            
            if ($table['status'] != 1) {
                return json(['code' => 400, 'message' => '台桌未开放']);
            }
            
            if ($table['run_status'] == 1) {
                return json(['code' => 400, 'message' => '游戏已在进行中']);
            }

            // 生成新游戏局号
            $gameNumber = $this->generateGameNumber($tableId);
            
            // 更新台桌状态为投注中
            Db::name('dianji_table')
                ->where('id', $tableId)
                ->update([
                    'run_status' => 1, // 投注中
                    'start_time' => time(),
                    'countdown_time' => $bettingTime,
                    'update_time' => time(),
                ]);

            // 缓存当前游戏信息
            $gameInfo = [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'round_number' => $this->getCurrentRoundNumber($tableId),
                'betting_start_time' => time(),
                'betting_end_time' => time() + $bettingTime,
                'status' => 'betting'
            ];
            
            Cache::set("sicbo:current_game:{$tableId}", $gameInfo, $bettingTime + 60);

            return json([
                'code' => 200,
                'message' => '游戏开始成功',
                'data' => [
                    'game_number' => $gameNumber,
                    'betting_time' => $bettingTime,
                    'countdown' => $bettingTime,
                    'status' => 'betting'
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500, 
                'message' => '开始游戏失败：' . $e->getMessage(),
                'debug' => [
                    'table_id' => $tableId,
                    'betting_time' => $bettingTime,
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 停止投注 - 修复版
     */
    public function stopBetting()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $gameNumber = $this->request->param('game_number', '');
        
        if ($tableId <= 0) {
            return json(['code' => 400, 'message' => '台桌ID无效']);
        }
        
        if (empty($gameNumber)) {
            return json(['code' => 400, 'message' => '游戏局号不能为空']);
        }

        try {
            // 检查游戏状态
            $currentGame = Cache::get("sicbo:current_game:{$tableId}");
            if (!$currentGame || $currentGame['game_number'] != $gameNumber) {
                return json(['code' => 400, 'message' => '游戏状态错误']);
            }

            // 更新台桌状态为开奖中
            Db::name('dianji_table')
                ->where('id', $tableId)
                ->update([
                    'run_status' => 2, // 开奖中
                    'update_time' => time(),
                ]);

            // 更新游戏状态
            $currentGame['status'] = 'dealing';
            Cache::set("sicbo:current_game:{$tableId}", $currentGame, 300);

            return json([
                'code' => 200,
                'message' => '投注已停止',
                'data' => [
                    'game_number' => $gameNumber,
                    'status' => 'dealing'
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500, 
                'message' => '停止投注失败：' . $e->getMessage(),
                'debug' => [
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 公布开奖结果 - 修复版
     */
    public function announceResult()
    {
        $params = $this->request->only(['table_id', 'game_number', 'dice1', 'dice2', 'dice3']);
        
        // 参数验证
        $tableId = (int)($params['table_id'] ?? 0);
        $gameNumber = $params['game_number'] ?? '';
        $dice1 = (int)($params['dice1'] ?? 0);
        $dice2 = (int)($params['dice2'] ?? 0);
        $dice3 = (int)($params['dice3'] ?? 0);
        
        if ($tableId <= 0 || empty($gameNumber)) {
            return json(['code' => 400, 'message' => '参数错误']);
        }
        
        if ($dice1 < 1 || $dice1 > 6 || $dice2 < 1 || $dice2 > 6 || $dice3 < 1 || $dice3 > 6) {
            return json(['code' => 400, 'message' => '骰子点数必须在1-6之间']);
        }

        try {
            // 检查游戏状态
            $currentGame = Cache::get("sicbo:current_game:{$tableId}");
            if (!$currentGame || $currentGame['game_number'] != $gameNumber) {
                return json(['code' => 400, 'message' => '游戏状态错误']);
            }

            // 检查是否已有结果
            $existingResult = Db::name('sicbo_game_results')
                ->where('game_number', $gameNumber)
                ->find();
                
            if ($existingResult) {
                return json(['code' => 400, 'message' => '该局游戏结果已存在']);
            }

            // 开始事务
            Db::startTrans();

            // 计算游戏结果属性
            $totalPoints = $dice1 + $dice2 + $dice3;
            $isBig = $totalPoints >= 11 && $totalPoints <= 17;
            $isOdd = $totalPoints % 2 == 1;
            
            // 检查三同号
            $hasTriple = ($dice1 == $dice2 && $dice2 == $dice3);
            $tripleNumber = $hasTriple ? $dice1 : 0;
            
            // 检查对子
            $hasPair = ($dice1 == $dice2 && $dice1 != $dice3) || 
                      ($dice1 == $dice3 && $dice1 != $dice2) || 
                      ($dice2 == $dice3 && $dice2 != $dice1);

            // 计算中奖投注类型
            $winningBets = $this->calculateWinningBets($dice1, $dice2, $dice3);

            // 创建游戏结果记录
            $gameResultId = Db::name('sicbo_game_results')->insertGetId([
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'round_number' => $currentGame['round_number'],
                'dice1' => $dice1,
                'dice2' => $dice2,
                'dice3' => $dice3,
                'total_points' => $totalPoints,
                'is_big' => $isBig ? 1 : 0,
                'is_odd' => $isOdd ? 1 : 0,
                'has_triple' => $hasTriple ? 1 : 0,
                'triple_number' => $tripleNumber,
                'has_pair' => $hasPair ? 1 : 0,
                'winning_bets' => json_encode($winningBets),
                'status' => 1,
                'created_at' => time(),
                'updated_at' => time()
            ]);

            if (!$gameResultId) {
                throw new \Exception('创建游戏结果失败');
            }

            // 触发投注结算（这里简化处理）
            // $this->triggerBetSettlement($gameNumber, $winningBets);

            // 更新台桌状态为等待中
            Db::name('dianji_table')
                ->where('id', $tableId)
                ->update([
                    'run_status' => 0, // 等待中
                    'update_time' => time(),
                ]);

            // 清除游戏缓存
            Cache::delete("sicbo:current_game:{$tableId}");

            // 提交事务
            Db::commit();

            return json([
                'code' => 200,
                'message' => '开奖成功',
                'data' => [
                    'game_number' => $gameNumber,
                    'dice1' => $dice1,
                    'dice2' => $dice2,
                    'dice3' => $dice3,
                    'total_points' => $totalPoints,
                    'is_big' => $isBig,
                    'is_odd' => $isOdd,
                    'has_triple' => $hasTriple,
                    'has_pair' => $hasPair,
                    'winning_bets' => $winningBets,
                    'status' => 'result'
                ]
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return json([
                'code' => 500, 
                'message' => '开奖失败：' . $e->getMessage(),
                'debug' => [
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'dices' => [$dice1, $dice2, $dice3],
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 获取当前投注统计 - 修复版
     */
    public function getCurrentBetStats()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $gameNumber = $this->request->param('game_number', '');
        
        if ($tableId <= 0) {
            return json(['code' => 400, 'message' => '台桌ID无效']);
        }

        try {
            // 获取当前局投注统计
            $betStats = [];
            if (!empty($gameNumber)) {
                $betStats = Db::name('sicbo_bet_records')
                    ->where('game_number', $gameNumber)
                    ->where('settle_status', 0) // 未结算
                    ->field('bet_type, count(*) as bet_count, sum(bet_amount) as total_amount')
                    ->group('bet_type')
                    ->select();
            }

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'bet_stats' => $betStats
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500, 
                'message' => '获取投注统计失败：' . $e->getMessage(),
                'debug' => [
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 获取赔率信息 - 修复版
     */
    public function getOddsInfo()
    {
        $tableId = $this->request->param('table_id/d', 0);

        try {
            // 获取所有有效赔率
            $odds = Db::name('sicbo_odds')
                ->where('status', 1)
                ->order('bet_category asc, bet_type asc')
                ->select();
            
            // 按分类组织赔率数据
            $organizedOdds = [];
            foreach ($odds as $odd) {
                $category = $odd['bet_category'] ?? 'basic';
                if (!isset($organizedOdds[$category])) {
                    $organizedOdds[$category] = [];
                }
                $organizedOdds[$category][] = [
                    'bet_type' => $odd['bet_type'],
                    'bet_name' => $odd['bet_name_cn'] ?? $odd['bet_type'],
                    'odds' => (float)$odd['odds'],
                    'min_bet' => (int)$odd['min_bet'],
                    'max_bet' => (int)$odd['max_bet'],
                ];
            }

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'table_id' => $tableId,
                    'odds' => $organizedOdds,
                    'update_time' => time()
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500, 
                'message' => '获取赔率信息失败：' . $e->getMessage(),
                'debug' => [
                    'table_id' => $tableId,
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    // ========================================
    // 私有辅助方法
    // ========================================

    /**
     * 获取当前游戏信息
     */
    private function getCurrentGame(int $tableId): ?array
    {
        return Cache::get("sicbo:current_game:{$tableId}");
    }

    /**
     * 获取最新游戏结果
     */
    private function getLastGame(int $tableId): ?array
    {
        return Db::name('sicbo_game_results')
            ->where('table_id', $tableId)
            ->where('status', 1)
            ->order('id desc')
            ->find();
    }

    /**
     * 计算今日统计
     */
    private function calculateTodayStats(int $tableId): array
    {
        $today = date('Y-m-d');
        $todayStart = strtotime($today . ' 00:00:00');
        $todayEnd = strtotime($today . ' 23:59:59');

        $stats = Db::name('sicbo_game_results')
            ->where('table_id', $tableId)
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<=', $todayEnd)
            ->field([
                'count(*) as total_rounds',
                'sum(is_big) as big_count',
                'sum(case when is_big = 0 then 1 else 0 end) as small_count',
                'sum(case when total_points % 2 = 1 then 1 else 0 end) as odd_count',
                'sum(case when total_points % 2 = 0 then 1 else 0 end) as even_count'
            ])
            ->find();

        return [
            'total_rounds' => (int)($stats['total_rounds'] ?? 0),
            'big_count' => (int)($stats['big_count'] ?? 0),
            'small_count' => (int)($stats['small_count'] ?? 0),
            'odd_count' => (int)($stats['odd_count'] ?? 0),
            'even_count' => (int)($stats['even_count'] ?? 0),
        ];
    }

    /**
     * 生成游戏局号
     */
    private function generateGameNumber(int $tableId): string
    {
        $prefix = 'T' . str_pad($tableId, 3, '0', STR_PAD_LEFT);
        $timestamp = date('YmdHis');
        $random = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        return $prefix . $timestamp . $random;
    }

    /**
     * 获取当前轮次号
     */
    private function getCurrentRoundNumber(int $tableId): int
    {
        $lastRound = Db::name('sicbo_game_results')
            ->where('table_id', $tableId)
            ->max('round_number');
        return ((int)$lastRound) + 1;
    }

    /**
     * 计算中奖投注类型
     */
    private function calculateWinningBets(int $dice1, int $dice2, int $dice3): array
    {
        $winningBets = [];
        $totalPoints = $dice1 + $dice2 + $dice3;
        $dices = [$dice1, $dice2, $dice3];
        sort($dices);

        // 大小
        if ($totalPoints >= 11 && $totalPoints <= 17) {
            $winningBets[] = 'big';
        } else {
            $winningBets[] = 'small';
        }

        // 单双
        if ($totalPoints % 2 === 1) {
            $winningBets[] = 'odd';
        } else {
            $winningBets[] = 'even';
        }

        // 总和
        $winningBets[] = "total-{$totalPoints}";

        // 单骰
        $diceCounts = array_count_values([$dice1, $dice2, $dice3]);
        foreach ($diceCounts as $dice => $count) {
            $winningBets[] = "single-{$dice}";
        }

        // 对子和三同号
        foreach ($diceCounts as $dice => $count) {
            if ($count === 2) {
                $winningBets[] = "pair-{$dice}";
            } elseif ($count === 3) {
                $winningBets[] = "triple-{$dice}";
                $winningBets[] = "any-triple";
            }
        }

        // 组合
        if (count(array_unique([$dice1, $dice2, $dice3])) >= 2) {
            $uniqueDices = array_unique([$dice1, $dice2, $dice3]);
            $uniqueDices = array_values($uniqueDices);
            sort($uniqueDices);
            
            for ($i = 0; $i < count($uniqueDices); $i++) {
                for ($j = $i + 1; $j < count($uniqueDices); $j++) {
                    $combo = "combo-{$uniqueDices[$i]}-{$uniqueDices[$j]}";
                    $winningBets[] = $combo;
                }
            }
        }

        return array_unique($winningBets);
    }

    /**
     * 获取胜率统计 - 简化版
     */
    private function getWinRateStats(int $tableId, string $period): array
    {
        // 简化实现，返回模拟数据
        return [
            'big_rate' => 48.6,
            'small_rate' => 51.4,
            'odd_rate' => 50.2,
            'even_rate' => 49.8
        ];
    }

    /**
     * 获取热门数字 - 简化版
     */
    private function getHotNumbers(int $tableId, int $days): array
    {
        // 简化实现，返回模拟数据
        return [
            ['number' => 3, 'count' => 25],
            ['number' => 5, 'count' => 22],
            ['number' => 4, 'count' => 20],
        ];
    }

    /**
     * 获取统计趋势 - 简化版
     */
    private function getStatsTrend(int $tableId, string $startDate, string $endDate): array
    {
        // 简化实现，返回模拟数据
        return [
            'dates' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d', strtotime('-5 days'))],
            'big_counts' => [12, 15],
            'small_counts' => [18, 13]
        ];
    }

    /**
     * 获取总和分布百分比 - 简化版
     */
    private function getTotalDistributionPercent(int $tableId, string $type): array
    {
        // 简化实现，返回模拟数据
        $distribution = [];
        for ($i = 4; $i <= 17; $i++) {
            $distribution[$i] = rand(2, 8);
        }
        return $distribution;
    }
}