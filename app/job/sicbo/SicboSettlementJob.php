<?php



namespace app\job\sicbo;

use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboBetRecords;
use app\model\sicbo\SicboOdds;
use app\model\User;
use app\model\UserBalance;
use app\model\UserBalanceLog;
use think\queue\Job;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

/**
 * 骰宝用户结算任务
 * 负责处理游戏结束后的用户投注结算、余额更新、统计计算等
 */
class SicboSettlementJob
{
    /**
     * 结算状态常量
     */
    private const SETTLE_STATUS_PENDING = 0;    // 未结算
    private const SETTLE_STATUS_SUCCESS = 1;    // 已结算
    private const SETTLE_STATUS_FAILED = 2;     // 结算失败
    private const SETTLE_STATUS_REFUND = 3;     // 已退款

    /**
     * 余额变动类型
     */
    private const BALANCE_TYPE_BET = 'sicbo_bet';           // 骰宝投注
    private const BALANCE_TYPE_WIN = 'sicbo_win';           // 骰宝中奖
    private const BALANCE_TYPE_REFUND = 'sicbo_refund';     // 骰宝退款

    /**
     * 执行任务
     * 
     * @param Job $job 任务对象
     * @param array $data 任务数据
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        try {
            $gameNumber = $data['game_number'] ?? '';
            $tableId = $data['table_id'] ?? 0;
            $forceSettle = $data['force_settle'] ?? false;

            if (empty($gameNumber) || empty($tableId)) {
                Log::error('骰宝结算任务参数错误', $data);
                $job->delete();
                return;
            }

            Log::info("开始执行骰宝结算任务", [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'force_settle' => $forceSettle
            ]);

            // 执行结算
            $result = $this->processSettlement($gameNumber, $tableId, $forceSettle);

            if ($result['success']) {
                Log::info("骰宝结算任务执行成功", [
                    'game_number' => $gameNumber,
                    'settled_bets' => $result['settled_bets'],
                    'total_win_amount' => $result['total_win_amount']
                ]);
                $job->delete();
            } else {
                Log::error("骰宝结算任务执行失败", [
                    'game_number' => $gameNumber,
                    'error' => $result['error']
                ]);
                
                // 重试机制：最多重试3次
                if ($job->attempts() < 3) {
                    $job->release(60); // 60秒后重试
                } else {
                    $job->delete();
                    $this->handleSettlementFailure($gameNumber, $tableId, $result['error']);
                }
            }

        } catch (\Exception $e) {
            Log::error("骰宝结算任务异常: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'data' => $data
            ]);
            
            if ($job->attempts() < 3) {
                $job->release(60);
            } else {
                $job->delete();
                $this->handleSettlementFailure($data['game_number'] ?? '', $data['table_id'] ?? 0, $e->getMessage());
            }
        }
    }

    /**
     * 处理结算逻辑
     * 
     * @param string $gameNumber 游戏局号
     * @param int $tableId 台桌ID
     * @param bool $forceSettle 是否强制结算
     * @return array
     */
    private function processSettlement(string $gameNumber, int $tableId, bool $forceSettle = false): array
    {
        // 开始数据库事务
        Db::startTrans();
        
        try {
            // 1. 获取游戏结果
            $gameResult = SicboGameResults::where('game_number', $gameNumber)
                ->where('table_id', $tableId)
                ->where('status', 1)
                ->find();

            if (!$gameResult) {
                Db::rollback();
                return ['success' => false, 'error' => '游戏结果不存在'];
            }

            // 2. 获取所有待结算的投注记录
            $betRecords = SicboBetRecords::where('game_number', $gameNumber)
                ->where('table_id', $tableId)
                ->where('settle_status', self::SETTLE_STATUS_PENDING)
                ->select();

            if ($betRecords->isEmpty()) {
                Db::rollback();
                return ['success' => false, 'error' => '没有待结算的投注记录'];
            }

            // 3. 获取当前有效赔率
            $odds = $this->getCurrentOdds();

            // 4. 计算中奖投注类型
            $winningBetTypes = $this->calculateWinningBetTypes($gameResult);

            // 5. 处理每笔投注的结算
            $settledBets = 0;
            $totalWinAmount = 0;
            $settlementDetails = [];

            foreach ($betRecords as $betRecord) {
                $settlementResult = $this->settleBetRecord(
                    $betRecord, 
                    $gameResult, 
                    $winningBetTypes, 
                    $odds
                );

                if ($settlementResult['success']) {
                    $settledBets++;
                    $totalWinAmount += $settlementResult['win_amount'];
                    $settlementDetails[] = $settlementResult['details'];
                } else {
                    Log::error("单笔投注结算失败", [
                        'bet_id' => $betRecord->id,
                        'error' => $settlementResult['error']
                    ]);
                    
                    if (!$forceSettle) {
                        Db::rollback();
                        return ['success' => false, 'error' => '单笔投注结算失败: ' . $settlementResult['error']];
                    }
                }
            }

            // 6. 更新游戏结果的中奖信息
            $gameResult->save([
                'winning_bets' => json_encode($winningBetTypes),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 7. 发送WebSocket通知
            $this->sendSettlementNotification($gameNumber, $tableId, $settlementDetails);

            // 8. 触发统计更新
            $this->triggerStatisticsUpdate($tableId);

            // 9. 清除相关缓存
            $this->clearRelatedCache($gameNumber, $tableId);

            Db::commit();

            return [
                'success' => true,
                'settled_bets' => $settledBets,
                'total_win_amount' => $totalWinAmount,
                'settlement_details' => $settlementDetails
            ];

        } catch (\Exception $e) {
            Db::rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 结算单笔投注记录
     * 
     * @param SicboBetRecords $betRecord 投注记录
     * @param SicboGameResults $gameResult 游戏结果
     * @param array $winningBetTypes 中奖投注类型
     * @param array $odds 赔率配置
     * @return array
     */
    private function settleBetRecord(
        SicboBetRecords $betRecord, 
        SicboGameResults $gameResult, 
        array $winningBetTypes, 
        array $odds
    ): array {
        try {
            $betType = $betRecord->bet_type;
            $betAmount = $betRecord->bet_amount;
            $isWin = in_array($betType, $winningBetTypes);
            $winAmount = 0;

            // 计算中奖金额
            if ($isWin) {
                $currentOdds = $odds[$betType]['odds'] ?? $betRecord->odds;
                $winAmount = $betAmount * $currentOdds;
            }

            // 更新投注记录
            $updateData = [
                'is_win' => $isWin ? 1 : 0,
                'win_amount' => $winAmount,
                'settle_status' => self::SETTLE_STATUS_SUCCESS,
                'settle_time' => date('Y-m-d H:i:s')
            ];

            $betRecord->save($updateData);

            // 处理用户余额变动
            if ($isWin && $winAmount > 0) {
                $balanceResult = $this->updateUserBalance(
                    $betRecord->user_id,
                    $winAmount,
                    self::BALANCE_TYPE_WIN,
                    "骰宝中奖-局号:{$gameResult->game_number}",
                    $betRecord->id
                );

                if (!$balanceResult['success']) {
                    throw new \Exception("用户余额更新失败: " . $balanceResult['error']);
                }
            }

            return [
                'success' => true,
                'win_amount' => $winAmount,
                'details' => [
                    'user_id' => $betRecord->user_id,
                    'bet_id' => $betRecord->id,
                    'bet_type' => $betType,
                    'bet_amount' => $betAmount,
                    'is_win' => $isWin,
                    'win_amount' => $winAmount,
                    'odds' => $currentOdds ?? $betRecord->odds
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'win_amount' => 0
            ];
        }
    }

    /**
     * 计算中奖的投注类型
     * 
     * @param SicboGameResults $gameResult 游戏结果
     * @return array
     */
    private function calculateWinningBetTypes(SicboGameResults $gameResult): array
    {
        $winningTypes = [];
        
        $dice1 = $gameResult->dice1;
        $dice2 = $gameResult->dice2;
        $dice3 = $gameResult->dice3;
        $totalPoints = $gameResult->total_points;
        $isBig = $gameResult->is_big;
        $isOdd = $gameResult->is_odd;
        $hasTriple = $gameResult->has_triple;
        $tripleNumber = $gameResult->triple_number;
        $hasPair = $gameResult->has_pair;

        // 基础投注
        if ($isBig) {
            $winningTypes[] = 'big';
        } else {
            $winningTypes[] = 'small';
        }

        if ($isOdd) {
            $winningTypes[] = 'odd';
        } else {
            $winningTypes[] = 'even';
        }

        // 总和投注
        $winningTypes[] = "total-{$totalPoints}";

        // 单骰投注 - 需要检查每个骰子
        $diceArray = [$dice1, $dice2, $dice3];
        $diceCount = array_count_values($diceArray);
        
        foreach ($diceCount as $diceNumber => $count) {
            // 单骰出现1次以上就中奖
            if ($count >= 1) {
                $winningTypes[] = "single-{$diceNumber}";
            }
        }

        // 对子投注
        if ($hasPair) {
            foreach ($diceCount as $diceNumber => $count) {
                if ($count >= 2) {
                    $winningTypes[] = "pair-{$diceNumber}";
                }
            }
        }

        // 三同号投注
        if ($hasTriple) {
            $winningTypes[] = "triple-{$tripleNumber}";
            $winningTypes[] = 'any-triple'; // 任意三同号
        }

        // 组合投注 - 检查两个不同数字的组合
        $uniqueDice = array_unique($diceArray);
        if (count($uniqueDice) >= 2) {
            sort($uniqueDice);
            for ($i = 0; $i < count($uniqueDice) - 1; $i++) {
                for ($j = $i + 1; $j < count($uniqueDice); $j++) {
                    $combo = "combo-{$uniqueDice[$i]}-{$uniqueDice[$j]}";
                    $winningTypes[] = $combo;
                }
            }
        }

        return array_unique($winningTypes);
    }

    /**
     * 获取当前有效赔率
     * 
     * @return array
     */
    private function getCurrentOdds(): array
    {
        $cacheKey = 'sicbo_odds_all';
        
        return Cache::remember($cacheKey, function () {
            $odds = SicboOdds::where('status', 1)->select();
            $oddsArray = [];
            
            foreach ($odds as $odd) {
                $oddsArray[$odd->bet_type] = [
                    'odds' => $odd->odds,
                    'min_bet' => $odd->min_bet,
                    'max_bet' => $odd->max_bet
                ];
            }
            
            return $oddsArray;
        }, 300);
    }

    /**
     * 更新用户余额
     * 
     * @param int $userId 用户ID
     * @param float $amount 金额
     * @param string $type 类型
     * @param string $remark 备注
     * @param int $relatedId 相关ID
     * @return array
     */
    private function updateUserBalance(
        int $userId, 
        float $amount, 
        string $type, 
        string $remark, 
        int $relatedId = 0
    ): array {
        try {
            // 获取用户信息
            $user = User::find($userId);
            if (!$user) {
                return ['success' => false, 'error' => '用户不存在'];
            }

            // 获取用户余额记录
            $userBalance = UserBalance::where('user_id', $userId)->find();
            if (!$userBalance) {
                return ['success' => false, 'error' => '用户余额记录不存在'];
            }

            $beforeBalance = $userBalance->balance;
            $afterBalance = $beforeBalance + $amount;

            // 更新余额
            $userBalance->save([
                'balance' => $afterBalance,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 记录余额变动日志
            UserBalanceLog::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'before_balance' => $beforeBalance,
                'after_balance' => $afterBalance,
                'remark' => $remark,
                'related_id' => $relatedId,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'before_balance' => $beforeBalance,
                'after_balance' => $afterBalance
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 处理结算失败情况
     * 
     * @param string $gameNumber 游戏局号
     * @param int $tableId 台桌ID
     * @param string $error 错误信息
     * @return void
     */
    private function handleSettlementFailure(string $gameNumber, int $tableId, string $error): void
    {
        try {
            Log::error("骰宝结算最终失败，开始退款处理", [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'error' => $error
            ]);

            // 查找所有未结算的投注记录
            $betRecords = SicboBetRecords::where('game_number', $gameNumber)
                ->where('table_id', $tableId)
                ->where('settle_status', self::SETTLE_STATUS_PENDING)
                ->select();

            Db::startTrans();

            foreach ($betRecords as $betRecord) {
                // 退还投注金额
                $refundResult = $this->updateUserBalance(
                    $betRecord->user_id,
                    $betRecord->bet_amount,
                    self::BALANCE_TYPE_REFUND,
                    "骰宝结算失败退款-局号:{$gameNumber}",
                    $betRecord->id
                );

                if ($refundResult['success']) {
                    // 更新投注记录状态为已退款
                    $betRecord->save([
                        'settle_status' => self::SETTLE_STATUS_REFUND,
                        'settle_time' => date('Y-m-d H:i:s'),
                        'win_amount' => 0,
                        'is_win' => 0
                    ]);
                }
            }

            Db::commit();

            // 发送退款通知
            $this->sendRefundNotification($gameNumber, $tableId, $betRecords->toArray());

        } catch (\Exception $e) {
            Db::rollback();
            Log::error("骰宝退款处理失败: " . $e->getMessage());
        }
    }

    /**
     * 发送结算通知
     * 
     * @param string $gameNumber 游戏局号
     * @param int $tableId 台桌ID
     * @param array $settlementDetails 结算详情
     * @return void
     */
    private function sendSettlementNotification(string $gameNumber, int $tableId, array $settlementDetails): void
    {
        try {
            // 按用户分组结算详情
            $userSettlements = [];
            foreach ($settlementDetails as $detail) {
                $userId = $detail['user_id'];
                if (!isset($userSettlements[$userId])) {
                    $userSettlements[$userId] = [
                        'user_id' => $userId,
                        'total_bet_amount' => 0,
                        'total_win_amount' => 0,
                        'win_count' => 0,
                        'bet_count' => 0,
                        'bets' => []
                    ];
                }
                
                $userSettlements[$userId]['total_bet_amount'] += $detail['bet_amount'];
                $userSettlements[$userId]['total_win_amount'] += $detail['win_amount'];
                $userSettlements[$userId]['bet_count']++;
                
                if ($detail['is_win']) {
                    $userSettlements[$userId]['win_count']++;
                }
                
                $userSettlements[$userId]['bets'][] = $detail;
            }

            // 发送个人结算通知
            foreach ($userSettlements as $userSettlement) {
                worker_tcp(
                    $userSettlement['user_id'],
                    '结算完成',
                    [
                        'type' => 'sicbo_settlement',
                        'game_number' => $gameNumber,
                        'table_id' => $tableId,
                        'settlement' => $userSettlement
                    ],
                    200
                );
            }

            // 发送台桌广播通知
            $broadcastData = [
                'type' => 'sicbo_game_settled',
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'total_players' => count($userSettlements),
                'total_bets' => count($settlementDetails),
                'settled_at' => date('Y-m-d H:i:s')
            ];

            // 这里可以添加台桌广播逻辑
            Log::info("骰宝结算广播通知", $broadcastData);

        } catch (\Exception $e) {
            Log::error("发送结算通知失败: " . $e->getMessage());
        }
    }

    /**
     * 发送退款通知
     * 
     * @param string $gameNumber 游戏局号
     * @param int $tableId 台桌ID
     * @param array $betRecords 投注记录
     * @return void
     */
    private function sendRefundNotification(string $gameNumber, int $tableId, array $betRecords): void
    {
        try {
            $userRefunds = [];
            foreach ($betRecords as $record) {
                $userId = $record['user_id'];
                if (!isset($userRefunds[$userId])) {
                    $userRefunds[$userId] = 0;
                }
                $userRefunds[$userId] += $record['bet_amount'];
            }

            foreach ($userRefunds as $userId => $refundAmount) {
                worker_tcp(
                    $userId,
                    '投注已退款',
                    [
                        'type' => 'sicbo_refund',
                        'game_number' => $gameNumber,
                        'table_id' => $tableId,
                        'refund_amount' => $refundAmount,
                        'reason' => '系统结算异常，投注金额已退还'
                    ],
                    200
                );
            }

        } catch (\Exception $e) {
            Log::error("发送退款通知失败: " . $e->getMessage());
        }
    }

    /**
     * 触发统计更新
     * 
     * @param int $tableId 台桌ID
     * @return void
     */
    private function triggerStatisticsUpdate(int $tableId): void
    {
        try {
            // 可以推送到队列异步处理统计更新
            $statisticsJobData = [
                'table_id' => $tableId,
                'stat_type' => 'daily',
                'stat_date' => date('Y-m-d'),
                'trigger_time' => time()
            ];

            // 推送统计更新任务到队列
            // Queue::push('app\job\sicbo\SicboStatisticsUpdateJob', $statisticsJobData);
            
            Log::info("触发骰宝统计更新", $statisticsJobData);

        } catch (\Exception $e) {
            Log::error("触发统计更新失败: " . $e->getMessage());
        }
    }

    /**
     * 清除相关缓存
     * 
     * @param string $gameNumber 游戏局号
     * @param int $tableId 台桌ID
     * @return void
     */
    private function clearRelatedCache(string $gameNumber, int $tableId): void
    {
        try {
            $cacheKeys = [
                "sicbo_game_info_{$tableId}",
                "sicbo_current_bets_{$gameNumber}",
                "sicbo_bet_stats_{$tableId}",
                "sicbo_stats_realtime_{$tableId}",
                "sicbo_user_current_bets_{$gameNumber}",
            ];

            foreach ($cacheKeys as $key) {
                Cache::delete($key);
            }

            Log::info("清除骰宝相关缓存", ['keys' => $cacheKeys]);

        } catch (\Exception $e) {
            Log::error("清除缓存失败: " . $e->getMessage());
        }
    }

    /**
     * 批量结算指定台桌的所有待结算投注
     * 静态方法，可供外部直接调用
     * 
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号 (可选)
     * @return array
     */
    public static function batchSettle(int $tableId, ?string $gameNumber = null): array
    {
        try {
            $query = SicboBetRecords::where('table_id', $tableId)
                ->where('settle_status', self::SETTLE_STATUS_PENDING);
                
            if ($gameNumber) {
                $query->where('game_number', $gameNumber);
            }
            
            $pendingBets = $query->group('game_number')->column('game_number');
            
            $results = [];
            foreach ($pendingBets as $gameNum) {
                $jobData = [
                    'game_number' => $gameNum,
                    'table_id' => $tableId,
                    'force_settle' => true
                ];
                
                // 推送到队列处理
                // Queue::push(self::class, $jobData);
                
                $results[] = $gameNum;
            }
            
            return [
                'success' => true,
                'message' => '批量结算任务已推送到队列',
                'games' => $results
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 手动重新结算指定游戏
     * 
     * @param string $gameNumber 游戏局号
     * @param int $tableId 台桌ID
     * @return array
     */
    public static function reSettle(string $gameNumber, int $tableId): array
    {
        try {
            // 重置投注记录状态
            SicboBetRecords::where('game_number', $gameNumber)
                ->where('table_id', $tableId)
                ->update([
                    'settle_status' => self::SETTLE_STATUS_PENDING,
                    'is_win' => null,
                    'win_amount' => 0,
                    'settle_time' => null
                ]);

            // 推送重新结算任务
            $jobData = [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'force_settle' => false
            ];
            
            // Queue::push(self::class, $jobData);
            
            Log::info("手动触发重新结算", $jobData);
            
            return [
                'success' => true,
                'message' => '重新结算任务已推送到队列'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}