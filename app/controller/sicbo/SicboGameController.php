<?php


namespace app\controller\sicbo;

use app\BaseController;
use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboOdds;
use app\model\sicbo\SicboStatistics;
use app\model\Table;
use app\validate\sicbo\SicboGameValidate;
use think\Response;
use think\exception\ValidateException;

/**
 * 骰宝游戏主控制器
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
        
        try {
            validate(SicboGameValidate::class)->scene('table_info')->check(['table_id' => $tableId]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        // 获取台桌基础信息
        $table = Table::where('id', $tableId)
            ->where('game_type', 9) // 骰宝游戏类型
            ->find();
        
        if (!$table) {
            return json(['code' => 404, 'message' => '台桌不存在']);
        }

        // 获取台桌状态信息
        $tableInfo = [
            'table_id' => $table->id,
            'table_name' => $table->table_title,
            'status' => $table->status,
            'run_status' => $table->run_status,
            'game_config' => $table->game_config,
        ];

        // 计算倒计时
        if ($table->run_status == 1) { // 投注中
            $endTime = time() - ($table->start_time + $table->countdown_time);
            $tableInfo['countdown'] = $endTime <= 0 ? abs($endTime) : 0;
        } else {
            $tableInfo['countdown'] = 0;
        }

        // 获取当前游戏局号
        $currentGame = $this->getCurrentGame($tableId);
        $tableInfo['current_game'] = $currentGame;

        // 获取最新开奖结果
        $latestResult = SicboGameResults::getLastGame($tableId);
        $tableInfo['latest_result'] = $latestResult;

        // 获取今日统计
        $todayStats = SicboStatistics::calculateTodayStats($tableId);
        $tableInfo['today_stats'] = $todayStats;

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => $tableInfo
        ]);
    }

    /**
     * 获取游戏历史记录
     * 路由: GET /sicbo/game/history
     */
    public function getGameHistory()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $limit = $this->request->param('limit/d', 20);
        
        try {
            validate(SicboGameValidate::class)->scene('game_history')->check([
                'table_id' => $tableId,
                'limit' => $limit
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $history = SicboGameResults::getLatestResults($tableId, $limit);
        
        // 格式化历史数据
        $formattedHistory = [];
        foreach ($history as $result) {
            $formattedHistory[] = [
                'game_number' => $result['game_number'],
                'dice1' => $result['dice1'],
                'dice2' => $result['dice2'],
                'dice3' => $result['dice3'],
                'total_points' => $result['total_points'],
                'is_big' => $result['is_big'],
                'is_odd' => $result['is_odd'],
                'has_triple' => $result['has_triple'],
                'triple_number' => $result['triple_number'],
                'has_pair' => $result['has_pair'],
                'created_at' => $result['created_at'],
            ];
        }

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'table_id' => $tableId,
                'history' => $formattedHistory,
                'count' => count($formattedHistory)
            ]
        ]);
    }

    /**
     * 获取游戏统计数据
     * 路由: GET /sicbo/game/statistics
     */
    public function getStatistics()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $type = $this->request->param('type', 'daily');
        
        try {
            validate(SicboGameValidate::class)->scene('statistics')->check([
                'table_id' => $tableId,
                'stat_type' => $type
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $statistics = [];

        switch ($type) {
            case 'daily':
                // 今日统计
                $statistics['today'] = SicboStatistics::calculateTodayStats($tableId);
                $statistics['win_rates'] = SicboStatistics::getWinRateStats($tableId, 'today');
                break;
                
            case 'weekly':
                // 本周统计
                $statistics['week'] = SicboStatistics::getWinRateStats($tableId, 'week');
                $statistics['hot_numbers'] = SicboStatistics::getHotNumbers($tableId, 7);
                break;
                
            case 'trend':
                // 趋势分析
                $startDate = date('Y-m-d', strtotime('-7 days'));
                $endDate = date('Y-m-d');
                $statistics['trend'] = SicboStatistics::getStatsTrend($tableId, $startDate, $endDate);
                break;
        }

        // 总点数分布
        $statistics['total_distribution'] = SicboStatistics::getTotalDistributionPercent($tableId, $type);

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => $statistics
        ]);
    }

    /**
     * 开始新游戏局
     * 路由: POST /sicbo/game/start
     */
    public function startNewGame()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $bettingTime = $this->request->param('betting_time/d', 30);
        
        try {
            validate(SicboGameValidate::class)->scene('start_game')->check([
                'table_id' => $tableId,
                'betting_time' => $bettingTime
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        // 检查台桌状态
        $table = Table::find($tableId);
        if (!$table || $table->game_type != 9) {
            return json(['code' => 404, 'message' => '台桌不存在或游戏类型错误']);
        }
        
        if ($table->status != 1) {
            return json(['code' => 400, 'message' => '台桌未开放']);
        }
        
        if ($table->run_status == 1) {
            return json(['code' => 400, 'message' => '游戏已在进行中']);
        }

        // 生成新游戏局号
        $gameNumber = SicboGameResults::generateGameNumber($tableId);
        
        // 更新台桌状态为投注中
        $table->save([
            'run_status' => 1, // 投注中
            'start_time' => time(),
            'countdown_time' => $bettingTime,
            'update_time' => time(),
        ]);

        // 缓存当前游戏信息
        $gameInfo = [
            'game_number' => $gameNumber,
            'table_id' => $tableId,
            'round_number' => SicboGameResults::getCurrentRoundNumber($tableId),
            'betting_start_time' => time(),
            'betting_end_time' => time() + $bettingTime,
            'status' => 'betting'
        ];
        
        cache("sicbo:current_game:{$tableId}", $gameInfo, $bettingTime + 60);

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
    }

    /**
     * 停止投注
     * 路由: POST /sicbo/game/stop-betting
     */
    public function stopBetting()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $gameNumber = $this->request->param('game_number', '');
        
        try {
            validate(SicboGameValidate::class)->scene('stop_betting')->check([
                'table_id' => $tableId,
                'game_number' => $gameNumber
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        // 检查游戏状态
        $currentGame = cache("sicbo:current_game:{$tableId}");
        if (!$currentGame || $currentGame['game_number'] != $gameNumber) {
            return json(['code' => 400, 'message' => '游戏状态错误']);
        }

        // 更新台桌状态为开奖中
        Table::where('id', $tableId)->update([
            'run_status' => 2, // 开奖中
            'update_time' => time(),
        ]);

        // 更新游戏状态
        $currentGame['status'] = 'dealing';
        cache("sicbo:current_game:{$tableId}", $currentGame, 300);

        return json([
            'code' => 200,
            'message' => '投注已停止',
            'data' => [
                'game_number' => $gameNumber,
                'status' => 'dealing'
            ]
        ]);
    }

    /**
     * 公布开奖结果
     * 路由: POST /sicbo/game/announce-result
     */
    public function announceResult()
    {
        $params = $this->request->only(['table_id', 'game_number', 'dice1', 'dice2', 'dice3']);
        
        try {
            validate(SicboGameValidate::class)->scene('announce_result')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $tableId = (int)$params['table_id'];
        $gameNumber = $params['game_number'];
        $dice1 = (int)$params['dice1'];
        $dice2 = (int)$params['dice2'];
        $dice3 = (int)$params['dice3'];

        // 检查游戏状态
        $currentGame = cache("sicbo:current_game:{$tableId}");
        if (!$currentGame || $currentGame['game_number'] != $gameNumber) {
            return json(['code' => 400, 'message' => '游戏状态错误']);
        }

        // 检查是否已有结果
        if (SicboGameResults::gameExists($gameNumber)) {
            return json(['code' => 400, 'message' => '该局游戏结果已存在']);
        }

        try {
            // 开始事务
            SicboGameResults::startTrans();

            // 创建游戏结果记录
            $gameResult = SicboGameResults::createGameResult([
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'round_number' => $currentGame['round_number'],
                'dice1' => $dice1,
                'dice2' => $dice2,
                'dice3' => $dice3,
            ]);

            if (!$gameResult) {
                throw new \Exception('创建游戏结果失败');
            }

            // 计算中奖投注类型
            $winningBets = $this->calculateWinningBets($dice1, $dice2, $dice3);

            // 更新游戏结果的中奖信息
            $gameResult->save(['winning_bets' => $winningBets]);

            // 触发投注结算（这里应该调用结算服务）
            $this->triggerBetSettlement($gameNumber, $winningBets);

            // 更新台桌状态为等待中
            Table::where('id', $tableId)->update([
                'run_status' => 0, // 等待中
                'update_time' => time(),
            ]);

            // 清除游戏缓存
            cache("sicbo:current_game:{$tableId}", null);

            // 提交事务
            SicboGameResults::commit();

            return json([
                'code' => 200,
                'message' => '开奖成功',
                'data' => [
                    'game_number' => $gameNumber,
                    'dice1' => $dice1,
                    'dice2' => $dice2,
                    'dice3' => $dice3,
                    'total_points' => $dice1 + $dice2 + $dice3,
                    'winning_bets' => $winningBets,
                    'status' => 'result'
                ]
            ]);

        } catch (\Exception $e) {
            SicboGameResults::rollback();
            return json(['code' => 500, 'message' => '开奖失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取当前投注统计
     * 路由: GET /sicbo/game/bet-stats
     */
    public function getCurrentBetStats()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $gameNumber = $this->request->param('game_number', '');
        
        try {
            validate(SicboGameValidate::class)->scene('bet_stats')->check([
                'table_id' => $tableId,
                'game_number' => $gameNumber
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        // 获取当前局投注统计
        $betStats = \app\model\sicbo\SicboBetRecords::getGameBetStats($gameNumber);

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'game_number' => $gameNumber,
                'bet_stats' => $betStats
            ]
        ]);
    }

    /**
     * 获取赔率信息
     * 路由: GET /sicbo/game/odds
     */
    public function getOddsInfo()
    {
        $tableId = $this->request->param('table_id/d', 0);

        // 获取所有有效赔率
        $odds = SicboOdds::getAllActiveOdds();
        
        // 按分类组织赔率数据
        $organizedOdds = [];
        foreach ($odds as $odd) {
            $category = $odd['bet_category'];
            if (!isset($organizedOdds[$category])) {
                $organizedOdds[$category] = [];
            }
            $organizedOdds[$category][] = [
                'bet_type' => $odd['bet_type'],
                'bet_name' => $odd['bet_name_cn'],
                'odds' => $odd['odds'],
                'min_bet' => $odd['min_bet'],
                'max_bet' => $odd['max_bet'],
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
    }

    /**
     * 获取当前游戏信息
     */
    private function getCurrentGame(int $tableId): ?array
    {
        return cache("sicbo:current_game:{$tableId}");
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
     * 触发投注结算
     */
    private function triggerBetSettlement(string $gameNumber, array $winningBets): void
    {
        // 这里应该调用投注结算服务
        // 可以是异步队列任务或者直接调用结算方法
        
        // 示例：添加到队列
        // Queue::push('app\job\SicboBetSettlementJob', [
        //     'game_number' => $gameNumber,
        //     'winning_bets' => $winningBets
        // ]);
        
        // 或者直接调用结算
        \app\model\sicbo\SicboBetRecords::settleBets($gameNumber, $winningBets);
    }
}