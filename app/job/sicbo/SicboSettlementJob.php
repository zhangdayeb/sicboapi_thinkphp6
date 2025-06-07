<?php

namespace app\job\sicbo;

use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboBetRecords;
use app\model\User;
use app\model\UserBalance;
use app\model\UserBalanceLog;
use think\queue\Job;
use think\facade\Db;
use think\facade\Log;

/**
 * 骰宝投注结算任务 - 简化版
 * 
 * 唯一职责：
 * 1. 读取开奖结果
 * 2. 计算所有投注的输赢
 * 3. 更新用户余额到数据库
 * 4. 触发WebSocket推送中奖信息
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
    private const BALANCE_TYPE_WIN = 'sicbo_win';       // 骰宝中奖
    private const BALANCE_TYPE_REFUND = 'sicbo_refund'; // 骰宝退款

    /**
     * 执行结算任务
     * 
     * @param Job $job 任务对象
     * @param array $data 任务数据 ['game_number' => string, 'table_id' => int]
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        $gameNumber = $data['game_number'] ?? '';
        $tableId = $data['table_id'] ?? 0;

        // 参数验证
        if (empty($gameNumber) || empty($tableId)) {
            Log::error('结算任务参数错误', $data);
            $job->delete();
            return;
        }

        Log::info("开始执行投注结算", [
            'game_number' => $gameNumber,
            'table_id' => $tableId
        ]);

        try {
            // 执行结算
            $result = $this->processSettlement($gameNumber, $tableId);

            if ($result['success']) {
                Log::info("投注结算完成", [
                    'game_number' => $gameNumber,
                    'settled_bets' => $result['settled_bets'],
                    'total_win_amount' => $result['total_win_amount'],
                    'winner_count' => $result['winner_count']
                ]);
                
                echo "[" . date('Y-m-d H:i:s') . "] 结算完成: {$gameNumber}, 投注数:{$result['settled_bets']}, 中奖:{$result['winner_count']}人, 总奖金:{$result['total_win_amount']}\n";
                
                $job->delete();
            } else {
                Log::error("投注结算失败", [
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
            Log::error("结算任务异常", [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            if ($job->attempts() < 3) {
                $job->release(60);
            } else {
                $job->delete();
                $this->handleSettlementFailure($gameNumber, $tableId, $e->getMessage());
            }
        }
    }

    /**
     * 处理投注结算
     * 
     * @param string $gameNumber 游戏局号
     * @param int $tableId 台桌ID
     * @return array
     */
    private function processSettlement(string $gameNumber, int $tableId): array
    {
        // 开始数据库事务
        Db::startTrans();
        
        try {
            // 1. 获取开奖结果
            $gameResult = $this->getGameResult($gameNumber, $tableId);
            if (!$gameResult) {
                Db::rollback();
                return ['success' => false, 'error' => '开奖结果不存在'];
            }

            // 2. 获取所有待结算的投注记录
            $betRecords = $this->getBetRecords($gameNumber, $tableId);
            if ($betRecords->isEmpty()) {
                Db::rollback();
                return ['success' => false, 'error' => '没有待结算的投注记录'];
            }

            // 3. 计算中奖投注类型
            $winningBetTypes = $this->calculateWinningBetTypes($gameResult);

            // 4. 处理每笔投注的结算
            $settlementResults = [];
            $settledBets = 0;
            $totalWinAmount = 0;
            $winnerCount = 0;

            foreach ($betRecords as $betRecord) {
                $settlement = $this->settleSingleBet($betRecord, $winningBetTypes);
                
                if ($settlement['success']) {
                    $settledBets++;
                    $totalWinAmount += $settlement['win_amount'];
                    
                    if ($settlement['is_win']) {
                        $winnerCount++;
                        $settlementResults[] = $settlement; // 只记录中奖的，用于推送
                    }
                } else {
                    // 单笔结算失败，回滚整个事务
                    Db::rollback();
                    return ['success' => false, 'error' => '单笔投注结算失败: ' . $settlement['error']];
                }
            }

            // 5. 提交事务
            Db::commit();

            // 6. 推送中奖信息
            $this->notifyWinners($settlementResults);

            return [
                'success' => true,
                'settled_bets' => $settledBets,
                'total_win_amount' => $totalWinAmount,
                'winner_count' => $winnerCount
            ];

        } catch (\Exception $e) {
            Db::rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 获取开奖结果
     * 
     * @param string $gameNumber
     * @param int $tableId
     * @return SicboGameResults|null
     */
    private function getGameResult(string $gameNumber, int $tableId): ?SicboGameResults
    {
        return SicboGameResults::where('game_number', $gameNumber)
            ->where('table_id', $tableId)
            ->where('status', 1)
            ->find();
    }

    /**
     * 获取待结算的投注记录
     * 
     * @param string $gameNumber
     * @param int $tableId
     * @return \think\Collection
     */
    private function getBetRecords(string $gameNumber, int $tableId)
    {
        return SicboBetRecords::where('game_number', $gameNumber)
            ->where('table_id', $tableId)
            ->where('settle_status', self::SETTLE_STATUS_PENDING)
            ->select();
    }

    /**
     * 计算中奖的投注类型
     * 
     * @param SicboGameResults $gameResult
     * @return array
     */
    private function calculateWinningBetTypes(SicboGameResults $gameResult): array
    {
        $winningTypes = [];
        
        $dice1 = $gameResult->dice1;
        $dice2 = $gameResult->dice2;
        $dice3 = $gameResult->dice3;
        $totalPoints = $dice1 + $dice2 + $dice3;
        
        // 计算基础属性
        $isBig = ($totalPoints >= 11 && $totalPoints <= 17);
        $isOdd = ($totalPoints % 2 === 1);
        $diceArray = [$dice1, $dice2, $dice3];
        $diceCount = array_count_values($diceArray);
        
        // 检查三同号和对子
        $hasTriple = false;
        $tripleNumber = null;
        $hasPair = false;
        
        foreach ($diceCount as $diceNumber => $count) {
            if ($count === 3) {
                $hasTriple = true;
                $tripleNumber = $diceNumber;
            } elseif ($count === 2) {
                $hasPair = true;
            }
        }

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

        // 单骰投注
        foreach ($diceCount as $diceNumber => $count) {
            $winningTypes[] = "single-{$diceNumber}";
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
            $winningTypes[] = 'any-triple';
        }

        // 组合投注
        $uniqueDice = array_unique($diceArray);
        if (count($uniqueDice) >= 2) {
            sort($uniqueDice);
            for ($i = 0; $i < count($uniqueDice) - 1; $i++) {
                for ($j = $i + 1; $j < count($uniqueDice); $j++) {
                    $winningTypes[] = "combo-{$uniqueDice[$i]}-{$uniqueDice[$j]}";
                }
            }
        }

        return array_unique($winningTypes);
    }

    /**
     * 结算单笔投注
     * 
     * @param SicboBetRecords $betRecord
     * @param array $winningBetTypes
     * @return array
     */
    private function settleSingleBet(SicboBetRecords $betRecord, array $winningBetTypes): array
    {
        try {
            $betType = $betRecord->bet_type;
            $betAmount = $betRecord->bet_amount;
            $odds = $betRecord->odds;
            $userId = $betRecord->user_id;
            
            // 判断是否中奖
            $isWin = in_array($betType, $winningBetTypes);
            $winAmount = $isWin ? $betAmount * $odds : 0;

            // 更新投注记录
            $betRecord->save([
                'is_win' => $isWin ? 1 : 0,
                'win_amount' => $winAmount,
                'settle_status' => self::SETTLE_STATUS_SUCCESS,
                'settle_time' => date('Y-m-d H:i:s')
            ]);

            // 如果中奖，更新用户余额
            if ($isWin && $winAmount > 0) {
                $balanceResult = $this->updateUserBalance($userId, $winAmount, $betRecord->id);
                if (!$balanceResult['success']) {
                    throw new \Exception("更新用户余额失败: " . $balanceResult['error']);
                }
            }

            return [
                'success' => true,
                'is_win' => $isWin,
                'win_amount' => $winAmount,
                'user_id' => $userId,
                'bet_id' => $betRecord->id,
                'bet_type' => $betType,
                'bet_amount' => $betAmount,
                'odds' => $odds,
                'new_balance' => $balanceResult['after_balance'] ?? 0
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 更新用户余额
     * 
     * @param int $userId
     * @param float $winAmount
     * @param int $betId
     * @return array
     */
    private function updateUserBalance(int $userId, float $winAmount, int $betId): array
    {
        try {
            // 获取用户余额记录
            $userBalance = UserBalance::where('user_id', $userId)->find();
            if (!$userBalance) {
                return ['success' => false, 'error' => '用户余额记录不存在'];
            }

            $beforeBalance = $userBalance->balance;
            $afterBalance = $beforeBalance + $winAmount;

            // 更新余额
            $userBalance->save([
                'balance' => $afterBalance,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 记录余额变动日志
            UserBalanceLog::create([
                'user_id' => $userId,
                'type' => self::BALANCE_TYPE_WIN,
                'amount' => $winAmount,
                'before_balance' => $beforeBalance,
                'after_balance' => $afterBalance,
                'remark' => "骰宝中奖",
                'related_id' => $betId,
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
     * 推送中奖信息给用户
     * 
     * @param array $settlementResults 中奖用户的结算结果
     * @return void
     */
    private function notifyWinners(array $settlementResults): void
    {
        try {
            // 按用户分组中奖信息
            $userWinnings = [];
            
            foreach ($settlementResults as $result) {
                $userId = $result['user_id'];
                
                if (!isset($userWinnings[$userId])) {
                    $userWinnings[$userId] = [
                        'user_id' => $userId,
                        'total_win_amount' => 0,
                        'win_bets' => [],
                        'new_balance' => $result['new_balance']
                    ];
                }
                
                $userWinnings[$userId]['total_win_amount'] += $result['win_amount'];
                $userWinnings[$userId]['win_bets'][] = [
                    'bet_type' => $result['bet_type'],
                    'bet_amount' => $result['bet_amount'],
                    'odds' => $result['odds'],
                    'win_amount' => $result['win_amount']
                ];
            }

            // 逐个推送给中奖用户
            foreach ($userWinnings as $userWinning) {
                $this->sendWinNotification($userWinning);
            }

            if (!empty($userWinnings)) {
                echo "[" . date('Y-m-d H:i:s') . "] 推送中奖信息: " . count($userWinnings) . "位中奖用户\n";
            }

        } catch (\Exception $e) {
            Log::error("推送中奖信息失败", [
                'error' => $e->getMessage(),
                'settlement_count' => count($settlementResults)
            ]);
        }
    }

    /**
     * 发送单个用户的中奖通知
     * 
     * @param array $userWinning
     * @return void
     */
    private function sendWinNotification(array $userWinning): void
    {
        try {
            $userId = $userWinning['user_id'];
            $totalWinAmount = $userWinning['total_win_amount'];
            $winBetsCount = count($userWinning['win_bets']);

            // 构建中奖信息
            $winData = [
                'type' => 'win_info',
                'data' => [
                    'user_id' => $userId,
                    'win_amount' => $totalWinAmount,
                    'win_bets' => $userWinning['win_bets'],
                    'win_bets_count' => $winBetsCount,
                    'new_balance' => $userWinning['new_balance'],
                    'message' => $this->formatWinMessage($totalWinAmount, $winBetsCount)
                ],
                'timestamp' => time()
            ];

            // 使用 worker_tcp 推送给用户
            worker_tcp($userId, '中奖通知', $winData, 200);

            Log::info("发送中奖通知", [
                'user_id' => $userId,
                'win_amount' => $totalWinAmount,
                'win_bets_count' => $winBetsCount
            ]);

        } catch (\Exception $e) {
            Log::error("发送中奖通知失败", [
                'user_id' => $userId ?? 0,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 格式化中奖消息
     * 
     * @param float $winAmount
     * @param int $winBetsCount
     * @return string
     */
    private function formatWinMessage(float $winAmount, int $winBetsCount): string
    {
        if ($winAmount >= 10000) {
            return "恭喜您！大奖中奖 ¥{$winAmount}，{$winBetsCount}项投注中奖！";
        } elseif ($winAmount >= 1000) {
            return "恭喜中奖 ¥{$winAmount}，{$winBetsCount}项投注中奖！";
        } else {
            return "中奖 ¥{$winAmount}，{$winBetsCount}项投注中奖";
        }
    }

    /**
     * 处理结算失败情况（退款）
     * 
     * @param string $gameNumber
     * @param int $tableId
     * @param string $error
     * @return void
     */
    private function handleSettlementFailure(string $gameNumber, int $tableId, string $error): void
    {
        try {
            Log::error("结算失败，开始退款处理", [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'error' => $error
            ]);

            // 查找所有未结算的投注记录
            $betRecords = SicboBetRecords::where('game_number', $gameNumber)
                ->where('table_id', $tableId)
                ->where('settle_status', self::SETTLE_STATUS_PENDING)
                ->select();

            if ($betRecords->isEmpty()) {
                return;
            }

            Db::startTrans();

            $refundCount = 0;
            foreach ($betRecords as $betRecord) {
                // 退还投注金额
                $refundResult = $this->updateUserBalance(
                    $betRecord->user_id,
                    $betRecord->bet_amount,
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
                    $refundCount++;
                }
            }

            Db::commit();

            Log::info("退款处理完成", [
                'game_number' => $gameNumber,
                'refund_count' => $refundCount
            ]);

            echo "[" . date('Y-m-d H:i:s') . "] 退款完成: {$gameNumber}, 退款{$refundCount}笔投注\n";

        } catch (\Exception $e) {
            Db::rollback();
            Log::error("退款处理失败", [
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 静态方法：触发结算任务
     * 
     * @param string $gameNumber
     * @param int $tableId
     * @return bool
     */
    public static function triggerSettlement(string $gameNumber, int $tableId): bool
    {
        try {
            $jobData = [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'timestamp' => time()
            ];

            // 推送到队列
            // Queue::push(self::class, $jobData);
            
            Log::info("触发结算任务", $jobData);
            echo "[" . date('Y-m-d H:i:s') . "] 触发结算任务: {$gameNumber}\n";
            
            return true;

        } catch (\Exception $e) {
            Log::error("触发结算任务失败", [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}