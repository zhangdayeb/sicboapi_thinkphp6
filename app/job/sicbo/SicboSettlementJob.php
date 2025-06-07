<?php

namespace app\job\sicbo;

use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboBetRecords;
use app\model\User;
use app\model\MoneyLog;
use app\service\sicbo\SicboCalculateService;
use app\service\sicbo\UserBalanceService;
use think\queue\Job;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

/**
 * 骰宝投注结算任务 - 简化版
 * 
 * 唯一职责：
 * 1. 读取开奖结果
 * 2. 计算所有投注的输赢（委托给Service）
 * 3. 更新用户余额到数据库
 * 4. 存储中奖信息到Redis，由WebSocket定时器推送
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
     * Redis Key 命名规范
     */
    private const REDIS_KEY_WIN_NOTIFICATIONS = 'sicbo:notifications:win';

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

            // 3. 使用Service计算中奖投注类型
            $winningBetTypes = SicboCalculateService::calculateWinningBetTypes($gameResult);

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

            // 6. 存储中奖信息到Redis
            $this->storeWinNotifications($gameNumber, $settlementResults);

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
            $newBalance = 0;
            if ($isWin && $winAmount > 0) {
                $balanceResult = UserBalanceService::addWinAmount($userId, $winAmount, $betRecord->id);
                if (!$balanceResult['success']) {
                    throw new \Exception("更新用户余额失败: " . $balanceResult['error']);
                }
                $newBalance = $balanceResult['after_balance'];
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
                'new_balance' => $newBalance
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 存储中奖信息到Redis
     * 
     * @param string $gameNumber
     * @param array $settlementResults 中奖用户的结算结果
     * @return void
     */
    private function storeWinNotifications(string $gameNumber, array $settlementResults): void
    {
        try {
            if (empty($settlementResults)) {
                return;
            }

            // 按用户分组中奖信息
            $userWinnings = [];
            
            foreach ($settlementResults as $result) {
                $userId = $result['user_id'];
                
                if (!isset($userWinnings[$userId])) {
                    $userWinnings[$userId] = [
                        'user_id' => $userId,
                        'game_number' => $gameNumber,
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

            // 批量存储到Redis
            $storedCount = 0;
            
            foreach ($userWinnings as $userWinning) {
                // 构建中奖通知数据
                $winNotification = [
                    'user_id' => $userWinning['user_id'],
                    'game_number' => $userWinning['game_number'],
                    'win_amount' => $userWinning['total_win_amount'],
                    'win_bets' => $userWinning['win_bets'],
                    'win_bets_count' => count($userWinning['win_bets']),
                    'new_balance' => $userWinning['new_balance'],
                    'message' => $this->formatWinMessage($userWinning['total_win_amount'], count($userWinning['win_bets'])),
                    'created_at' => time()
                ];

                // 存储到Redis队列
                Cache::store('redis')->lPush(self::REDIS_KEY_WIN_NOTIFICATIONS, json_encode($winNotification));
                $storedCount++;
                
                echo "[" . date('Y-m-d H:i:s') . "] 中奖信息入队: UserID {$userWinning['user_id']}, 金额 {$userWinning['total_win_amount']}\n";
            }

            // 设置队列过期时间（24小时）
            if ($storedCount > 0) {
                Cache::store('redis')->expire(self::REDIS_KEY_WIN_NOTIFICATIONS, 86400);
                
                Log::info("中奖信息批量存储完成", [
                    'game_number' => $gameNumber,
                    'stored_count' => $storedCount,
                    'total_win_users' => count($userWinnings)
                ]);
                
                echo "[" . date('Y-m-d H:i:s') . "] 批量存储中奖信息完成: {$storedCount}位中奖用户\n";
            }

        } catch (\Exception $e) {
            Log::error("存储中奖信息到Redis失败", [
                'game_number' => $gameNumber,
                'error' => $e->getMessage(),
                'settlement_count' => count($settlementResults)
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
     * 处理结算失败情况 - 简化版
     * 不自动退款，保持等待状态，让后台人员手动处理
     * 
     * @param string $gameNumber
     * @param int $tableId
     * @param string $error
     * @return void
     */
    private function handleSettlementFailure(string $gameNumber, int $tableId, string $error): void
    {
        try {
            // 只记录失败日志，不做任何自动操作
            Log::error("结算失败，需要人工处理", [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'error' => $error,
                'status' => '投注记录保持等待状态',
                'action_required' => '需要后台人员手动处理'
            ]);

            // 统计待处理的投注数量
            $pendingBetsCount = SicboBetRecords::where('game_number', $gameNumber)
                ->where('table_id', $tableId)
                ->where('settle_status', self::SETTLE_STATUS_PENDING)
                ->count();

            echo "[" . date('Y-m-d H:i:s') . "] 结算失败: {$gameNumber}, {$pendingBetsCount}笔投注需要人工处理\n";

        } catch (\Exception $e) {
            Log::error("结算失败处理异常", [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'original_error' => $error,
                'handling_error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 静态方法：直接同步执行结算任务
     * 
     * @param string $gameNumber
     * @param int $tableId
     * @return bool
     */
    public static function triggerSettlement(string $gameNumber, int $tableId): bool
    {
        try {
            // 直接同步执行结算，不使用队列
            $job = new self();
            $jobData = [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'timestamp' => time()
            ];

            // 模拟Job对象（用于兼容fire方法的参数）
            $mockJob = new class {
                private $attempts = 1;
                public function attempts() { return $this->attempts; }
                public function delete() { return true; }
                public function release($delay) { $this->attempts++; }
            };

            $job->fire($mockJob, $jobData);
            
            Log::info("同步执行结算任务完成", $jobData);
            
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

    /**
     * 获取Redis中奖通知队列的Key
     * 供WebSocket定时器使用
     * 
     * @return string
     */
    public static function getWinNotificationsRedisKey(): string
    {
        return self::REDIS_KEY_WIN_NOTIFICATIONS;
    }
}