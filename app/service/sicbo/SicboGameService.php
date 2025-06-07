<?php


namespace app\service\sicbo;

use app\controller\common\LogHelper;
use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboBetRecords;
use app\model\sicbo\SicboOdds;
use app\model\sicbo\SicboStatistics;
use app\model\Table;
use app\model\UserModel;
use think\facade\Cache;
use think\facade\Queue;
use think\facade\Db;

/**
 * ========================================
 * 骰宝游戏逻辑服务类
 * ========================================
 * 
 * 功能概述：
 * - 游戏流程控制和状态管理
 * - 台桌运营和配置管理
 * - 游戏局生命周期管理
 * - 投注时间控制和验证
 * - 游戏数据和历史管理
 * - 实时状态推送和通知
 * - 风控和限制管理
 * 
 * 游戏流程：
 * 1. 开始新局 -> 投注期 -> 停止投注 -> 开奖 -> 结算 -> 等待下局
 * 2. 支持手动和自动模式
 * 3. 完整的状态验证和转换
 * 4. 异常处理和恢复机制
 * 
 * @package app\service\sicbo
 * @author  系统开发团队
 * @version 1.0
 */
class SicboGameService
{
    /**
     * 游戏状态常量
     */
    const GAME_STATUS_WAITING = 'waiting';      // 等待开始
    const GAME_STATUS_BETTING = 'betting';      // 投注中
    const GAME_STATUS_DEALING = 'dealing';      // 开奖中
    const GAME_STATUS_SETTLING = 'settling';    // 结算中
    const GAME_STATUS_FINISHED = 'finished';    // 已完成
    const GAME_STATUS_CANCELLED = 'cancelled';  // 已取消

    /**
     * 台桌运营状态
     */
    const TABLE_STATUS_CLOSED = 0;      // 关闭
    const TABLE_STATUS_OPEN = 1;        // 开放
    const TABLE_STATUS_MAINTENANCE = 2; // 维护

    /**
     * 台桌运行状态
     */
    const TABLE_RUN_STATUS_IDLE = 0;    // 空闲
    const TABLE_RUN_STATUS_BETTING = 1; // 投注中
    const TABLE_RUN_STATUS_DEALING = 2; // 开奖中

    /**
     * 缓存键前缀
     */
    const CACHE_PREFIX_GAME = 'sicbo:game:';
    const CACHE_PREFIX_TABLE = 'sicbo:table:';
    const CACHE_PREFIX_CONFIG = 'sicbo:config:';

    /**
     * 服务依赖注入
     */
    private SicboCalculationService $calculationService;
    private SicboSettlementService $settlementService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->calculationService = new SicboCalculationService();
        $this->settlementService = new SicboSettlementService();
    }

    /**
     * ========================================
     * 游戏流程控制方法
     * ========================================
     */

    /**
     * 开始新游戏局
     * 
     * @param int $tableId 台桌ID
     * @param int $bettingDuration 投注时长（秒）
     * @param int $dealerId 荷官ID（可选）
     * @return array 游戏开始结果
     */
    public function startNewGame(int $tableId, int $bettingDuration = 30, int $dealerId = 0): array
    {
        LogHelper::debug('=== 开始新游戏局 ===', [
            'table_id' => $tableId,
            'betting_duration' => $bettingDuration,
            'dealer_id' => $dealerId
        ]);

        try {
            // 1. 验证台桌状态
            $table = $this->validateTableForNewGame($tableId);
            
            // 2. 生成新游戏局号
            $gameNumber = $this->generateGameNumber($tableId);
            
            // 3. 计算当前轮次
            $roundNumber = $this->getCurrentRoundNumber($tableId);
            
            // 4. 创建游戏会话
            $gameSession = $this->createGameSession($tableId, $gameNumber, $roundNumber, $bettingDuration, $dealerId);
            
            // 5. 更新台桌状态
            $this->updateTableStatus($tableId, self::TABLE_RUN_STATUS_BETTING, $bettingDuration);
            
            // 6. 缓存游戏信息
            $this->cacheGameSession($gameSession);
            
            // 7. 设置投注结束定时器
            $this->scheduleStopBetting($gameSession);
            
            // 8. 发送游戏开始通知
            $this->broadcastGameStart($gameSession);

            LogHelper::debug('新游戏局创建成功', [
                'game_number' => $gameNumber,
                'round_number' => $roundNumber,
                'betting_end_time' => $gameSession['betting_end_time']
            ]);

            return [
                'success' => true,
                'game_number' => $gameNumber,
                'round_number' => $roundNumber,
                'table_id' => $tableId,
                'betting_duration' => $bettingDuration,
                'betting_end_time' => $gameSession['betting_end_time'],
                'countdown' => $bettingDuration,
                'status' => self::GAME_STATUS_BETTING
            ];

        } catch (\Exception $e) {
            LogHelper::error('开始新游戏局失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    /**
     * 停止投注
     * 
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号（可选，用于验证）
     * @return array 停止投注结果
     */
    public function stopBetting(int $tableId, string $gameNumber = ''): array
    {
        LogHelper::debug('停止投注', [
            'table_id' => $tableId,
            'game_number' => $gameNumber
        ]);

        try {
            // 1. 获取当前游戏会话
            $gameSession = $this->getCurrentGameSession($tableId);
            
            if (!$gameSession) {
                throw new \Exception("台桌 {$tableId} 没有进行中的游戏");
            }

            // 2. 验证游戏号（如果提供）
            if (!empty($gameNumber) && $gameSession['game_number'] !== $gameNumber) {
                throw new \Exception("游戏局号不匹配");
            }

            // 3. 验证游戏状态
            if ($gameSession['status'] !== self::GAME_STATUS_BETTING) {
                throw new \Exception("当前游戏状态不允许停止投注");
            }

            // 4. 更新游戏状态
            $gameSession['status'] = self::GAME_STATUS_DEALING;
            $gameSession['betting_stopped_at'] = time();
            
            // 5. 更新台桌状态
            $this->updateTableStatus($tableId, self::TABLE_RUN_STATUS_DEALING);
            
            // 6. 更新缓存
            $this->cacheGameSession($gameSession);
            
            // 7. 获取投注统计
            $betStats = $this->getBettingStatistics($gameSession['game_number']);
            
            // 8. 发送停止投注通知
            $this->broadcastStopBetting($gameSession, $betStats);

            LogHelper::debug('投注停止成功', [
                'game_number' => $gameSession['game_number'],
                'bet_count' => $betStats['total_bets'],
                'bet_amount' => $betStats['total_amount']
            ]);

            return [
                'success' => true,
                'game_number' => $gameSession['game_number'],
                'status' => self::GAME_STATUS_DEALING,
                'betting_stats' => $betStats,
                'stopped_at' => $gameSession['betting_stopped_at']
            ];

        } catch (\Exception $e) {
            LogHelper::error('停止投注失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * 开奖并结算
     * 
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @param int $dice1 骰子1点数
     * @param int $dice2 骰子2点数
     * @param int $dice3 骰子3点数
     * @param int $dealerId 荷官ID（可选）
     * @return array 开奖结算结果
     */
    public function openAndSettle(int $tableId, string $gameNumber, int $dice1, int $dice2, int $dice3, int $dealerId = 0): array
    {
        LogHelper::debug('=== 开奖并结算 ===', [
            'table_id' => $tableId,
            'game_number' => $gameNumber,
            'dice1' => $dice1,
            'dice2' => $dice2,
            'dice3' => $dice3,
            'dealer_id' => $dealerId
        ]);

        try {
            // 1. 验证游戏状态
            $gameSession = $this->validateGameForOpening($tableId, $gameNumber);
            
            // 2. 验证骰子数据
            $this->validateDiceValues($dice1, $dice2, $dice3);
            
            // 3. 更新游戏状态为结算中
            $gameSession['status'] = self::GAME_STATUS_SETTLING;
            $gameSession['dice1'] = $dice1;
            $gameSession['dice2'] = $dice2;
            $gameSession['dice3'] = $dice3;
            $gameSession['opened_at'] = time();
            $gameSession['dealer_id'] = $dealerId;
            
            $this->cacheGameSession($gameSession);
            
            // 4. 计算游戏结果
            $gameResult = $this->calculationService->calculateGameResult($dice1, $dice2, $dice3);
            
            // 5. 创建游戏结果记录
            $this->createGameResultRecord($gameSession, $gameResult);
            
            // 6. 执行结算
            $settlementResult = $this->settlementService->settleGame($gameNumber, $dice1, $dice2, $dice3);
            
            // 7. 更新游戏状态为已完成
            $gameSession['status'] = self::GAME_STATUS_FINISHED;
            $gameSession['settled_at'] = time();
            $gameSession['settlement_result'] = $settlementResult;
            
            // 8. 更新台桌状态为空闲
            $this->updateTableStatus($tableId, self::TABLE_RUN_STATUS_IDLE);
            
            // 9. 缓存最终游戏状态（延长缓存时间用于查询）
            $this->cacheGameSession($gameSession, 1800); // 30分钟
            
            // 10. 发送开奖结果通知
            $this->broadcastGameResult($gameSession, $gameResult, $settlementResult);
            
            // 11. 更新统计数据
            $this->updateGameStatistics($tableId, $gameSession, $settlementResult);

            LogHelper::debug('开奖结算完成', [
                'game_number' => $gameNumber,
                'game_result' => $this->calculationService->formatGameResult($gameResult),
                'total_bets' => $settlementResult['total_bets'],
                'house_profit' => $settlementResult['house_profit']
            ]);

            return [
                'success' => true,
                'game_number' => $gameNumber,
                'game_result' => [
                    'dice1' => $dice1,
                    'dice2' => $dice2,
                    'dice3' => $dice3,
                    'total_points' => $gameResult['total_points'],
                    'is_big' => $gameResult['is_big'],
                    'is_odd' => $gameResult['is_odd'],
                    'has_triple' => $gameResult['has_triple'],
                    'triple_number' => $gameResult['triple_number'],
                    'has_pair' => $gameResult['has_pair'],
                    'winning_bets' => $gameResult['winning_bets']
                ],
                'settlement_result' => $settlementResult,
                'status' => self::GAME_STATUS_FINISHED,
                'opened_at' => $gameSession['opened_at'],
                'settled_at' => $gameSession['settled_at']
            ];

        } catch (\Exception $e) {
            // 标记游戏为失败状态
            $this->markGameFailed($tableId, $gameNumber, $e->getMessage());
            
            LogHelper::error('开奖结算失败', [
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    /**
     * 取消游戏局
     * 
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @param string $reason 取消原因
     * @param int $operatorId 操作员ID
     * @return array 取消结果
     */
    public function cancelGame(int $tableId, string $gameNumber, string $reason, int $operatorId = 0): array
    {
        LogHelper::debug('取消游戏局', [
            'table_id' => $tableId,
            'game_number' => $gameNumber,
            'reason' => $reason,
            'operator_id' => $operatorId
        ]);

        try {
            // 1. 获取游戏会话
            $gameSession = $this->getCurrentGameSession($tableId);
            
            if (!$gameSession || $gameSession['game_number'] !== $gameNumber) {
                throw new \Exception("游戏局不存在或已结束");
            }

            // 2. 验证游戏状态
            if (in_array($gameSession['status'], [self::GAME_STATUS_FINISHED, self::GAME_STATUS_CANCELLED])) {
                throw new \Exception("游戏已结束，无法取消");
            }

            // 3. 开始取消流程
            Db::startTrans();
            
            try {
                // 4. 退还所有投注
                $refundResult = $this->refundAllBets($gameNumber, $reason);
                
                // 5. 更新游戏状态
                $gameSession['status'] = self::GAME_STATUS_CANCELLED;
                $gameSession['cancelled_at'] = time();
                $gameSession['cancel_reason'] = $reason;
                $gameSession['operator_id'] = $operatorId;
                $gameSession['refund_result'] = $refundResult;
                
                // 6. 更新台桌状态
                $this->updateTableStatus($tableId, self::TABLE_RUN_STATUS_IDLE);
                
                // 7. 更新游戏结果记录
                $this->updateGameResultRecord($gameNumber, [
                    'status' => 0, // 取消状态
                    'cancel_reason' => $reason,
                    'cancelled_at' => date('Y-m-d H:i:s')
                ]);

                Db::commit();
                
                // 8. 缓存取消状态
                $this->cacheGameSession($gameSession, 1800);
                
                // 9. 发送取消通知
                $this->broadcastGameCancellation($gameSession, $refundResult);

                LogHelper::debug('游戏局取消成功', [
                    'game_number' => $gameNumber,
                    'refund_count' => $refundResult['refund_count'],
                    'refund_amount' => $refundResult['refund_amount']
                ]);

                return [
                    'success' => true,
                    'game_number' => $gameNumber,
                    'status' => self::GAME_STATUS_CANCELLED,
                    'reason' => $reason,
                    'refund_result' => $refundResult,
                    'cancelled_at' => $gameSession['cancelled_at']
                ];

            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            LogHelper::error('取消游戏局失败', [
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * ========================================
     * 台桌管理方法
     * ========================================
     */

    /**
     * 获取台桌完整状态信息
     * 
     * @param int $tableId 台桌ID
     * @return array 台桌状态信息
     */
    public function getTableStatus(int $tableId): array
    {
        try {
            // 1. 获取台桌基础信息
            $table = Table::find($tableId);
            if (!$table || $table->game_type != 9) {
                throw new \Exception("台桌不存在或类型错误");
            }

            // 2. 获取当前游戏会话
            $currentGame = $this->getCurrentGameSession($tableId);
            
            // 3. 计算倒计时
            $countdown = 0;
            if ($currentGame && $currentGame['status'] === self::GAME_STATUS_BETTING) {
                $countdown = max(0, $currentGame['betting_end_time'] - time());
            }

            // 4. 获取最近游戏历史
            $recentGames = $this->getRecentGameResults($tableId, 10);
            
            // 5. 获取今日统计
            $todayStats = SicboStatistics::calculateTodayStats($tableId);
            
            // 6. 获取在线玩家数
            $onlineCount = $this->getTableOnlineCount($tableId);

            return [
                'table_id' => $tableId,
                'table_name' => $table->table_title,
                'table_status' => $table->status,
                'run_status' => $table->run_status,
                'game_config' => $table->game_config,
                'current_game' => $currentGame,
                'countdown' => $countdown,
                'recent_games' => $recentGames,
                'today_stats' => $todayStats,
                'online_count' => $onlineCount,
                'last_update' => time()
            ];

        } catch (\Exception $e) {
            LogHelper::error('获取台桌状态失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * 更新台桌配置
     * 
     * @param int $tableId 台桌ID
     * @param array $config 配置参数
     * @return bool 更新结果
     */
    public function updateTableConfig(int $tableId, array $config): bool
    {
        try {
            $table = Table::find($tableId);
            if (!$table) {
                throw new \Exception("台桌不存在");
            }

            // 验证配置参数
            $validatedConfig = $this->validateTableConfig($config);
            
            // 更新台桌配置
            $updateData = [];
            if (isset($validatedConfig['betting_time'])) {
                $currentConfig = is_array($table->game_config) ? $table->game_config : json_decode($table->game_config, true);
                $currentConfig['betting_time'] = $validatedConfig['betting_time'];
                $updateData['game_config'] = $currentConfig;
            }
            
            if (isset($validatedConfig['table_title'])) {
                $updateData['table_title'] = $validatedConfig['table_title'];
            }

            if (!empty($updateData)) {
                $updateData['update_time'] = time();
                $table->save($updateData);
                
                // 清除相关缓存
                $this->clearTableCache($tableId);
            }

            LogHelper::debug('台桌配置更新成功', [
                'table_id' => $tableId,
                'updated_fields' => array_keys($updateData)
            ]);

            return true;

        } catch (\Exception $e) {
            LogHelper::error('更新台桌配置失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * 切换台桌运营状态
     * 
     * @param int $tableId 台桌ID
     * @param int $status 目标状态
     * @param string $reason 操作原因
     * @return bool 操作结果
     */
    public function toggleTableOperationStatus(int $tableId, int $status, string $reason = ''): bool
    {
        try {
            $table = Table::find($tableId);
            if (!$table) {
                throw new \Exception("台桌不存在");
            }

            // 如果要关闭台桌，先处理当前游戏
            if ($status === self::TABLE_STATUS_CLOSED || $status === self::TABLE_STATUS_MAINTENANCE) {
                $currentGame = $this->getCurrentGameSession($tableId);
                if ($currentGame && !in_array($currentGame['status'], [self::GAME_STATUS_FINISHED, self::GAME_STATUS_CANCELLED])) {
                    // 取消当前游戏
                    $this->cancelGame($tableId, $currentGame['game_number'], $reason ?: '台桌维护');
                }
            }

            // 更新台桌状态
            $table->save([
                'status' => $status,
                'run_status' => self::TABLE_RUN_STATUS_IDLE,
                'update_time' => time()
            ]);

            // 清除缓存
            $this->clearTableCache($tableId);

            LogHelper::debug('台桌状态切换成功', [
                'table_id' => $tableId,
                'new_status' => $status,
                'reason' => $reason
            ]);

            return true;

        } catch (\Exception $e) {
            LogHelper::error('切换台桌状态失败', [
                'table_id' => $tableId,
                'target_status' => $status,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * ========================================
     * 游戏数据查询方法
     * ========================================
     */

    /**
     * 获取游戏历史记录
     * 
     * @param int $tableId 台桌ID
     * @param int $limit 数量限制
     * @param string $startDate 开始日期（可选）
     * @param string $endDate 结束日期（可选）
     * @return array 游戏历史记录
     */
    public function getGameHistory(int $tableId, int $limit = 20, string $startDate = '', string $endDate = ''): array
    {
        try {
            $query = SicboGameResults::where('table_id', $tableId)
                ->where('status', '>', 0) // 排除取消的游戏
                ->order('id desc')
                ->limit($limit);

            if (!empty($startDate)) {
                $query->where('created_at', '>=', $startDate . ' 00:00:00');
            }
            
            if (!empty($endDate)) {
                $query->where('created_at', '<=', $endDate . ' 23:59:59');
            }

            $results = $query->select()->toArray();

            // 格式化结果
            $formattedResults = [];
            foreach ($results as $result) {
                $formattedResults[] = [
                    'game_number' => $result['game_number'],
                    'round_number' => $result['round_number'],
                    'dice1' => $result['dice1'],
                    'dice2' => $result['dice2'],
                    'dice3' => $result['dice3'],
                    'total_points' => $result['total_points'],
                    'is_big' => $result['is_big'],
                    'is_odd' => $result['is_odd'],
                    'has_triple' => $result['has_triple'],
                    'triple_number' => $result['triple_number'],
                    'has_pair' => $result['has_pair'],
                    'winning_bets' => $result['winning_bets'],
                    'player_count' => $result['player_count'],
                    'total_bet_amount' => $result['total_bet_amount'],
                    'total_win_amount' => $result['total_win_amount'],
                    'house_profit' => $result['house_profit'],
                    'created_at' => $result['created_at']
                ];
            }

            return [
                'table_id' => $tableId,
                'games' => $formattedResults,
                'count' => count($formattedResults),
                'query_params' => [
                    'limit' => $limit,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            ];

        } catch (\Exception $e) {
            LogHelper::error('获取游戏历史失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * 获取投注统计信息
     * 
     * @param string $gameNumber 游戏局号
     * @return array 投注统计
     */
    public function getBettingStatistics(string $gameNumber): array
    {
        try {
            $stats = SicboBetRecords::where('game_number', $gameNumber)
                ->field([
                    'bet_type',
                    'count(*) as bet_count',
                    'sum(bet_amount) as total_amount',
                    'count(distinct user_id) as unique_users'
                ])
                ->group('bet_type')
                ->select()
                ->toArray();

            $totalStats = SicboBetRecords::where('game_number', $gameNumber)
                ->field([
                    'count(*) as total_bets',
                    'sum(bet_amount) as total_amount',
                    'count(distinct user_id) as total_users',
                    'avg(bet_amount) as avg_bet_amount',
                    'max(bet_amount) as max_bet_amount'
                ])
                ->find();

            return [
                'game_number' => $gameNumber,
                'by_bet_type' => $stats,
                'total_stats' => $totalStats ? $totalStats->toArray() : [
                    'total_bets' => 0,
                    'total_amount' => 0,
                    'total_users' => 0,
                    'avg_bet_amount' => 0,
                    'max_bet_amount' => 0
                ]
            ];

        } catch (\Exception $e) {
            LogHelper::error('获取投注统计失败', [
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
            
            return [
                'game_number' => $gameNumber,
                'by_bet_type' => [],
                'total_stats' => [
                    'total_bets' => 0,
                    'total_amount' => 0,
                    'total_users' => 0,
                    'avg_bet_amount' => 0,
                    'max_bet_amount' => 0
                ]
            ];
        }
    }

    /**
     * 获取用户游戏记录
     * 
     * @param int $userId 用户ID
     * @param int $tableId 台桌ID（可选）
     * @param int $limit 数量限制
     * @return array 用户游戏记录
     */
    public function getUserGameRecords(int $userId, int $tableId = 0, int $limit = 50): array
    {
        try {
            $query = SicboBetRecords::alias('br')
                ->leftJoin('sicbo_game_results gr', 'br.game_number = gr.game_number')
                ->where('br.user_id', $userId)
                ->order('br.bet_time desc')
                ->limit($limit);

            if ($tableId > 0) {
                $query->where('br.table_id', $tableId);
            }

            $records = $query->field([
                'br.game_number',
                'br.table_id', 
                'br.bet_type',
                'br.bet_amount',
                'br.odds',
                'br.is_win',
                'br.win_amount',
                'br.bet_time',
                'br.settle_time',
                'gr.dice1',
                'gr.dice2', 
                'gr.dice3',
                'gr.total_points',
                'gr.is_big',
                'gr.is_odd'
            ])->select()->toArray();

            // 计算统计数据
            $totalBetAmount = array_sum(array_column($records, 'bet_amount'));
            $totalWinAmount = array_sum(array_column($records, 'win_amount'));
            $winCount = count(array_filter($records, fn($r) => $r['is_win'] == 1));

            return [
                'user_id' => $userId,
                'table_id' => $tableId,
                'records' => $records,
                'statistics' => [
                    'total_games' => count($records),
                    'total_bet_amount' => $totalBetAmount,
                    'total_win_amount' => $totalWinAmount,
                    'net_result' => $totalWinAmount - $totalBetAmount,
                    'win_count' => $winCount,
                    'win_rate' => count($records) > 0 ? round($winCount / count($records) * 100, 2) : 0
                ]
            ];

        } catch (\Exception $e) {
            LogHelper::error('获取用户游戏记录失败', [
                'user_id' => $userId,
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * ========================================
     * 私有辅助方法
     * ========================================
     */

    /**
     * 验证台桌是否可以开始新游戏
     */
    private function validateTableForNewGame(int $tableId): Table
    {
        $table = Table::find($tableId);
        
        if (!$table) {
            throw new \Exception("台桌不存在");
        }
        
        if ($table->game_type != 9) {
            throw new \Exception("台桌游戏类型错误");
        }
        
        if ($table->status != self::TABLE_STATUS_OPEN) {
            throw new \Exception("台桌未开放");
        }
        
        if ($table->run_status != self::TABLE_RUN_STATUS_IDLE) {
            throw new \Exception("台桌正在运行中");
        }

        // 检查是否有未完成的游戏
        $currentGame = $this->getCurrentGameSession($tableId);
        if ($currentGame && !in_array($currentGame['status'], [self::GAME_STATUS_FINISHED, self::GAME_STATUS_CANCELLED])) {
            throw new \Exception("存在未完成的游戏局");
        }

        return $table;
    }

    /**
     * 生成游戏局号
     */
    private function generateGameNumber(int $tableId): string
    {
        $date = date('Ymd');
        $roundNumber = $this->getCurrentRoundNumber($tableId);
        return "SB{$date}T{$tableId}R{$roundNumber}";
    }

    /**
     * 获取当前轮次号
     */
    private function getCurrentRoundNumber(int $tableId): int
    {
        $today = date('Y-m-d');
        $count = SicboGameResults::where('table_id', $tableId)
            ->whereTime('created_at', 'today')
            ->count();
        
        return $count + 1;
    }

    /**
     * 创建游戏会话
     */
    private function createGameSession(int $tableId, string $gameNumber, int $roundNumber, int $bettingDuration, int $dealerId): array
    {
        $now = time();
        
        return [
            'game_number' => $gameNumber,
            'table_id' => $tableId,
            'round_number' => $roundNumber,
            'dealer_id' => $dealerId,
            'status' => self::GAME_STATUS_BETTING,
            'betting_start_time' => $now,
            'betting_end_time' => $now + $bettingDuration,
            'betting_duration' => $bettingDuration,
            'created_at' => $now
        ];
    }

    /**
     * 验证开奖数据
     */
    private function validateGameForOpening(int $tableId, string $gameNumber): array
    {
        $gameSession = $this->getCurrentGameSession($tableId);
        
        if (!$gameSession) {
            throw new \Exception("没有进行中的游戏");
        }
        
        if ($gameSession['game_number'] !== $gameNumber) {
            throw new \Exception("游戏局号不匹配");
        }
        
        if (!in_array($gameSession['status'], [self::GAME_STATUS_BETTING, self::GAME_STATUS_DEALING])) {
            throw new \Exception("游戏状态不允许开奖");
        }

        return $gameSession;
    }

    /**
     * 验证骰子数值
     */
    private function validateDiceValues(int $dice1, int $dice2, int $dice3): void
    {
        foreach ([$dice1, $dice2, $dice3] as $index => $dice) {
            if ($dice < 1 || $dice > 6) {
                throw new \InvalidArgumentException("骰子" . ($index + 1) . "点数无效: {$dice}");
            }
        }
    }

    /**
     * 更新台桌状态
     */
    private function updateTableStatus(int $tableId, int $runStatus, int $countdownTime = 0): void
    {
        $updateData = [
            'run_status' => $runStatus,
            'update_time' => time()
        ];

        if ($runStatus === self::TABLE_RUN_STATUS_BETTING && $countdownTime > 0) {
            $updateData['start_time'] = time();
            $updateData['countdown_time'] = $countdownTime;
        }

        Table::where('id', $tableId)->update($updateData);
        
        // 清除台桌缓存
        $this->clearTableCache($tableId);
    }

    /**
     * 缓存游戏会话
     */
    private function cacheGameSession(array $gameSession, int $ttl = 3600): void
    {
        $key = self::CACHE_PREFIX_GAME . $gameSession['table_id'];
        Cache::set($key, $gameSession, $ttl);
    }

    /**
     * 获取当前游戏会话
     */
    private function getCurrentGameSession(int $tableId): ?array
    {
        $key = self::CACHE_PREFIX_GAME . $tableId;
        return Cache::get($key);
    }

    /**
     * 创建游戏结果记录
     */
    private function createGameResultRecord(array $gameSession, array $gameResult): void
    {
        $data = [
            'table_id' => $gameSession['table_id'],
            'game_number' => $gameSession['game_number'],
            'round_number' => $gameSession['round_number'],
            'dice1' => $gameResult['dice1'],
            'dice2' => $gameResult['dice2'],
            'dice3' => $gameResult['dice3'],
            'total_points' => $gameResult['total_points'],
            'is_big' => $gameResult['is_big'],
            'is_odd' => $gameResult['is_odd'],
            'has_triple' => $gameResult['has_triple'],
            'triple_number' => $gameResult['triple_number'],
            'has_pair' => $gameResult['has_pair'],
            'pair_numbers' => implode(',', $gameResult['pair_numbers'] ?? []),
            'winning_bets' => $gameResult['winning_bets'],
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];

        SicboGameResults::create($data);
    }

    /**
     * 设置投注结束定时器
     */
    private function scheduleStopBetting(array $gameSession): void
    {
        $delay = $gameSession['betting_duration'];
        
        Queue::later($delay, 'app\job\sicbo\SicboAutoStopBettingJob', [
            'table_id' => $gameSession['table_id'],
            'game_number' => $gameSession['game_number']
        ]);
    }

    /**
     * 退还所有投注
     */
    private function refundAllBets(string $gameNumber, string $reason): array
    {
        $pendingBets = SicboBetRecords::where('game_number', $gameNumber)
            ->where('settle_status', SicboBetRecords::SETTLE_STATUS_PENDING)
            ->select();

        $refundCount = 0;
        $refundAmount = 0;

        foreach ($pendingBets as $bet) {
            // 退还给用户
            UserModel::where('id', $bet['user_id'])->inc('money_balance', $bet['bet_amount'])->update();
            
            // 更新投注状态
            $bet->save([
                'settle_status' => SicboBetRecords::SETTLE_STATUS_CANCELLED,
                'settle_time' => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason
            ]);

            $refundCount++;
            $refundAmount += $bet['bet_amount'];
        }

        return [
            'refund_count' => $refundCount,
            'refund_amount' => $refundAmount
        ];
    }

    /**
     * 标记游戏失败
     */
    private function markGameFailed(int $tableId, string $gameNumber, string $errorMessage): void
    {
        // 更新台桌状态
        $this->updateTableStatus($tableId, self::TABLE_RUN_STATUS_IDLE);
        
        // 更新游戏会话状态
        $gameSession = $this->getCurrentGameSession($tableId);
        if ($gameSession) {
            $gameSession['status'] = 'failed';
            $gameSession['error_message'] = $errorMessage;
            $gameSession['failed_at'] = time();
            $this->cacheGameSession($gameSession, 1800);
        }
    }

    /**
     * 获取最近游戏结果
     */
    private function getRecentGameResults(int $tableId, int $limit): array
    {
        return SicboGameResults::where('table_id', $tableId)
            ->where('status', 1)
            ->order('id desc')
            ->limit($limit)
            ->field(['dice1', 'dice2', 'dice3', 'total_points', 'is_big', 'is_odd', 'created_at'])
            ->select()
            ->toArray();
    }

    /**
     * 获取台桌在线人数
     */
    private function getTableOnlineCount(int $tableId): int
    {
        // 这里应该从WebSocket连接管理器获取
        return Cache::get("sicbo:online_count:{$tableId}", 0);
    }

    /**
     * 验证台桌配置
     */
    private function validateTableConfig(array $config): array
    {
        $validated = [];
        
        if (isset($config['betting_time'])) {
            $bettingTime = (int)$config['betting_time'];
            if ($bettingTime < 10 || $bettingTime > 300) {
                throw new \InvalidArgumentException("投注时间必须在10-300秒之间");
            }
            $validated['betting_time'] = $bettingTime;
        }
        
        if (isset($config['table_title'])) {
            $title = trim($config['table_title']);
            if (empty($title) || strlen($title) > 50) {
                throw new \InvalidArgumentException("台桌名称长度必须在1-50字符之间");
            }
            $validated['table_title'] = $title;
        }

        return $validated;
    }

    /**
     * 清除台桌相关缓存
     */
    private function clearTableCache(int $tableId): void
    {
        Cache::delete(self::CACHE_PREFIX_TABLE . $tableId);
        Cache::delete(self::CACHE_PREFIX_GAME . $tableId);
    }

    /**
     * 更新游戏结果记录
     */
    private function updateGameResultRecord(string $gameNumber, array $data): void
    {
        SicboGameResults::where('game_number', $gameNumber)->update($data);
    }

    /**
     * 更新游戏统计
     */
    private function updateGameStatistics(int $tableId, array $gameSession, array $settlementResult): void
    {
        // 添加到异步队列处理
        Queue::push('app\job\sicbo\SicboStatisticsJob', [
            'table_id' => $tableId,
            'game_session' => $gameSession,
            'settlement_result' => $settlementResult
        ]);
    }

    /**
     * 广播游戏开始
     */
    private function broadcastGameStart(array $gameSession): void
    {
        // WebSocket广播实现
        Cache::set("sicbo:broadcast:game_start:{$gameSession['table_id']}", $gameSession, 60);
    }

    /**
     * 广播停止投注
     */
    private function broadcastStopBetting(array $gameSession, array $betStats): void
    {
        $data = ['game_session' => $gameSession, 'bet_stats' => $betStats];
        Cache::set("sicbo:broadcast:stop_betting:{$gameSession['table_id']}", $data, 60);
    }

    /**
     * 广播游戏结果
     */
    private function broadcastGameResult(array $gameSession, array $gameResult, array $settlementResult): void
    {
        $data = [
            'game_session' => $gameSession,
            'game_result' => $gameResult,
            'settlement_result' => $settlementResult
        ];
        Cache::set("sicbo:broadcast:game_result:{$gameSession['table_id']}", $data, 300);
    }

    /**
     * 广播游戏取消
     */
    private function broadcastGameCancellation(array $gameSession, array $refundResult): void
    {
        $data = ['game_session' => $gameSession, 'refund_result' => $refundResult];
        Cache::set("sicbo:broadcast:game_cancelled:{$gameSession['table_id']}", $data, 300);
    }
}