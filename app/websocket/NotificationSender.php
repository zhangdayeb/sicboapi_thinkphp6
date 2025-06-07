<?php

namespace app\websocket;

use app\websocket\ConnectionManager;
use think\facade\Log;

/**
 * 推送发送器 - 5个核心推送
 * 专注于骰宝游戏必需的实时推送功能
 * 适配 PHP 7.3 + ThinkPHP6
 */
class NotificationSender
{
    /**
     * 推送类型常量
     */
    const TYPE_GAME_START = 'game_start';
    const TYPE_COUNTDOWN = 'countdown';
    const TYPE_GAME_END = 'game_end';
    const TYPE_GAME_RESULT = 'game_result';
    const TYPE_WIN_INFO = 'win_info';

    /**
     * 推送优先级
     */
    const PRIORITY_NORMAL = 2;
    const PRIORITY_HIGH = 3;
    const PRIORITY_URGENT = 4;

    /**
     * 推送统计数据
     * @var array
     */
    private static $stats = [
        'total_sent' => 0,
        'game_start_sent' => 0,
        'countdown_sent' => 0,
        'game_end_sent' => 0,
        'game_result_sent' => 0,
        'win_info_sent' => 0,
        'failed_count' => 0,
        'last_reset' => 0
    ];

    /**
     * 推送1: 发送游戏开始通知
     * @param int $tableId 台桌ID
     * @param array $gameData 游戏数据
     * @return int 发送成功的连接数
     */
    public static function sendGameStart($tableId, array $gameData)
    {
        try {
            $message = [
                'type' => self::TYPE_GAME_START,
                'data' => [
                    'table_id' => $tableId,
                    'game_number' => $gameData['game_number'] ?? '',
                    'round_number' => $gameData['round_number'] ?? 1,
                    'total_time' => $gameData['countdown'] ?? 30,
                    'start_time' => time(),
                    'betting_end_time' => $gameData['betting_end_time'] ?? (time() + 30),
                    'message' => '新一局游戏开始，请准备投注'
                ],
                'timestamp' => time(),
                'priority' => self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            // 更新统计
            self::updateStats('game_start', $sentCount);
            
            // 记录日志
            self::logNotification(self::TYPE_GAME_START, $tableId, $gameData, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            self::handleSendError(self::TYPE_GAME_START, $tableId, $e);
            return 0;
        }
    }

    /**
     * 推送2: 发送倒计时更新通知
     * @param int $tableId 台桌ID
     * @param array $countdownData 倒计时数据
     * @return int 发送成功的连接数
     */
    public static function sendCountdown($tableId, array $countdownData)
    {
        try {
            $remainingTime = $countdownData['remaining_time'] ?? 0;
            $totalTime = $countdownData['total_time'] ?? 30;

            $message = [
                'type' => self::TYPE_COUNTDOWN,
                'data' => [
                    'table_id' => $tableId,
                    'game_number' => $countdownData['game_number'] ?? '',
                    'remaining_time' => $remainingTime,
                    'total_time' => $totalTime,
                    'message' => self::getCountdownMessage($remainingTime)
                ],
                'timestamp' => time(),
                'priority' => $remainingTime <= 5 ? self::PRIORITY_HIGH : self::PRIORITY_NORMAL
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            // 更新统计
            self::updateStats('countdown', $sentCount);
            
            // 记录关键倒计时日志
            if ($remainingTime <= 5 || in_array($remainingTime, [30, 20, 10])) {
                self::logNotification(self::TYPE_COUNTDOWN, $tableId, $countdownData, $sentCount);
            }
            
            return $sentCount;
            
        } catch (\Exception $e) {
            self::handleSendError(self::TYPE_COUNTDOWN, $tableId, $e);
            return 0;
        }
    }

    /**
     * 推送3: 发送游戏结束通知
     * @param int $tableId 台桌ID
     * @param array $gameData 游戏数据
     * @return int 发送成功的连接数
     */
    public static function sendGameEnd($tableId, array $gameData)
    {
        try {
            $message = [
                'type' => self::TYPE_GAME_END,
                'data' => [
                    'table_id' => $tableId,
                    'game_number' => $gameData['game_number'] ?? '',
                    'end_time' => $gameData['end_time'] ?? time(),
                    'message' => '投注已截止，准备开奖'
                ],
                'timestamp' => time(),
                'priority' => self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            // 更新统计
            self::updateStats('game_end', $sentCount);
            
            // 记录日志
            self::logNotification(self::TYPE_GAME_END, $tableId, $gameData, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            self::handleSendError(self::TYPE_GAME_END, $tableId, $e);
            return 0;
        }
    }

    /**
     * 推送4: 发送开奖结果通知
     * @param int $tableId 台桌ID
     * @param array $gameData 游戏数据
     * @param array $result 开奖结果
     * @return int 发送成功的连接数
     */
    public static function sendGameResult($tableId, array $gameData, array $result)
    {
        try {
            // 确保骰子数据有效
            $dice1 = (int)($result['dice1'] ?? 1);
            $dice2 = (int)($result['dice2'] ?? 1);
            $dice3 = (int)($result['dice3'] ?? 1);
            
            // 计算基础属性
            $totalPoints = $dice1 + $dice2 + $dice3;
            $isBig = $totalPoints >= 11 && $totalPoints <= 17;
            $isOdd = $totalPoints % 2 === 1;
            $hasTriple = ($dice1 === $dice2 && $dice2 === $dice3);
            $hasPair = !$hasTriple && (
                ($dice1 === $dice2) || ($dice2 === $dice3) || ($dice1 === $dice3)
            );

            $message = [
                'type' => self::TYPE_GAME_RESULT,
                'data' => [
                    'table_id' => $tableId,
                    'game_number' => $gameData['game_number'] ?? '',
                    'round_number' => $gameData['round_number'] ?? 1,
                    'result' => [
                        'dice1' => $dice1,
                        'dice2' => $dice2,
                        'dice3' => $dice3,
                        'total_points' => $totalPoints,
                        'is_big' => $isBig,
                        'is_small' => !$isBig,
                        'is_odd' => $isOdd,
                        'is_even' => !$isOdd,
                        'has_triple' => $hasTriple,
                        'triple_number' => $hasTriple ? $dice1 : null,
                        'has_pair' => $hasPair,
                        'winning_bets' => $result['winning_bets'] ?? []
                    ],
                    'result_time' => $result['result_time'] ?? time(),
                    'message' => self::formatResultMessage($dice1, $dice2, $dice3, $totalPoints, $isBig, $isOdd, $hasTriple)
                ],
                'timestamp' => time(),
                'priority' => self::PRIORITY_URGENT
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            // 更新统计
            self::updateStats('game_result', $sentCount);
            
            // 记录日志
            self::logNotification(self::TYPE_GAME_RESULT, $tableId, array_merge($gameData, $result), $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            self::handleSendError(self::TYPE_GAME_RESULT, $tableId, $e);
            return 0;
        }
    }

    /**
     * 推送5: 发送个人中奖信息通知
     * @param int $userId 用户ID
     * @param array $winData 中奖数据
     * @return int 发送成功的连接数
     */
    public static function sendWinInfo($userId, array $winData)
    {
        try {
            $winAmount = (float)($winData['win_amount'] ?? 0);
            
            if ($winAmount <= 0) {
                return 0; // 没有中奖，不发送
            }

            $message = [
                'type' => self::TYPE_WIN_INFO,
                'data' => [
                    'user_id' => $userId,
                    'game_number' => $winData['game_number'] ?? '',
                    'win_amount' => $winAmount,
                    'win_bets' => $winData['win_bets'] ?? [],
                    'new_balance' => $winData['new_balance'] ?? 0,
                    'message' => self::formatWinMessage($winAmount, $winData['win_bets'] ?? [])
                ],
                'timestamp' => time(),
                'priority' => $winAmount >= 1000 ? self::PRIORITY_URGENT : self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::sendToUser($userId, $message);
            
            // 更新统计
            self::updateStats('win_info', $sentCount);
            
            // 记录中奖日志
            self::logWinNotification($userId, $winData, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            self::handleSendError(self::TYPE_WIN_INFO, $userId, $e);
            return 0;
        }
    }

    /**
     * 批量发送推送（辅助方法）
     * @param array $notifications 推送数据数组
     * @return array 发送结果
     */
    public static function batchSend(array $notifications)
    {
        $results = [];
        
        foreach ($notifications as $index => $notification) {
            try {
                $type = $notification['type'] ?? '';
                $target = $notification['target'] ?? null;
                $data = $notification['data'] ?? [];
                
                $sentCount = 0;
                
                switch ($type) {
                    case self::TYPE_GAME_START:
                        $sentCount = self::sendGameStart($target, $data);
                        break;
                        
                    case self::TYPE_COUNTDOWN:
                        $sentCount = self::sendCountdown($target, $data);
                        break;
                        
                    case self::TYPE_GAME_END:
                        $sentCount = self::sendGameEnd($target, $data);
                        break;
                        
                    case self::TYPE_GAME_RESULT:
                        $sentCount = self::sendGameResult($target, $data, $data['result'] ?? []);
                        break;
                        
                    case self::TYPE_WIN_INFO:
                        $sentCount = self::sendWinInfo($target, $data);
                        break;
                        
                    default:
                        throw new \Exception('Unknown notification type: ' . $type);
                }
                
                $results[$index] = [
                    'success' => true,
                    'sent_count' => $sentCount
                ];
                
            } catch (\Exception $e) {
                $results[$index] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                
                Log::error('批量发送推送失败', [
                    'index' => $index,
                    'notification' => $notification,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }

    /**
     * 格式化开奖结果消息
     * @param int $dice1
     * @param int $dice2
     * @param int $dice3
     * @param int $totalPoints
     * @param bool $isBig
     * @param bool $isOdd
     * @param bool $hasTriple
     * @return string
     */
    private static function formatResultMessage($dice1, $dice2, $dice3, $totalPoints, $isBig, $isOdd, $hasTriple)
    {
        $diceText = "{$dice1}-{$dice2}-{$dice3}";
        $bigSmall = $isBig ? '大' : '小';
        $oddEven = $isOdd ? '单' : '双';
        
        $message = "开奖结果：{$diceText}，总点数：{$totalPoints}，{$bigSmall}/{$oddEven}";
        
        if ($hasTriple) {
            $message .= "，三同号 {$dice1}";
        }
        
        return $message;
    }

    /**
     * 格式化中奖消息
     * @param float $winAmount
     * @param array $winBets
     * @return string
     */
    private static function formatWinMessage($winAmount, array $winBets)
    {
        $betCount = count($winBets);
        
        if ($winAmount >= 10000) {
            return "恭喜您！大奖中奖 ¥{$winAmount}，{$betCount}项投注中奖！";
        } elseif ($winAmount >= 1000) {
            return "恭喜中奖 ¥{$winAmount}，{$betCount}项投注中奖！";
        } else {
            return "中奖 ¥{$winAmount}，{$betCount}项投注中奖";
        }
    }

    /**
     * 格式化倒计时消息
     * @param int $remainingTime
     * @return string
     */
    private static function getCountdownMessage($remainingTime)
    {
        if ($remainingTime <= 0) {
            return '投注时间结束';
        } elseif ($remainingTime <= 5) {
            return "还有 {$remainingTime} 秒";
        } elseif ($remainingTime <= 10) {
            return "倒计时 {$remainingTime} 秒";
        } else {
            return "剩余 {$remainingTime} 秒";
        }
    }

    /**
     * 更新推送统计
     * @param string $type
     * @param int $sentCount
     */
    private static function updateStats($type, $sentCount)
    {
        if (self::$stats['last_reset'] === 0) {
            self::$stats['last_reset'] = time();
        }

        self::$stats['total_sent'] += $sentCount;
        self::$stats[$type . '_sent'] += $sentCount;
        
        if ($sentCount === 0) {
            self::$stats['failed_count']++;
        }
    }

    /**
     * 记录推送日志
     * @param string $type
     * @param int $target
     * @param array $data
     * @param int $sentCount
     */
    private static function logNotification($type, $target, array $data, $sentCount)
    {
        Log::info('WebSocket推送发送', [
            'type' => $type,
            'target' => $target,
            'sent_count' => $sentCount,
            'data' => $data,
            'timestamp' => time()
        ]);
    }

    /**
     * 记录中奖推送日志
     * @param int $userId
     * @param array $winData
     * @param int $sentCount
     */
    private static function logWinNotification($userId, array $winData, $sentCount)
    {
        Log::info('中奖信息推送', [
            'user_id' => $userId,
            'win_amount' => $winData['win_amount'] ?? 0,
            'game_number' => $winData['game_number'] ?? '',
            'win_bets_count' => count($winData['win_bets'] ?? []),
            'sent_count' => $sentCount,
            'timestamp' => time()
        ]);
    }

    /**
     * 处理发送错误
     * @param string $type
     * @param mixed $target
     * @param \Exception $e
     */
    private static function handleSendError($type, $target, \Exception $e)
    {
        self::$stats['failed_count']++;
        
        Log::error('推送发送失败', [
            'type' => $type,
            'target' => $target,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    /**
     * 获取推送统计信息
     * @return array
     */
    public static function getStats()
    {
        $runtime = time() - self::$stats['last_reset'];
        
        return array_merge(self::$stats, [
            'runtime_seconds' => $runtime,
            'avg_per_minute' => $runtime > 0 ? round((self::$stats['total_sent'] / $runtime) * 60, 2) : 0,
            'success_rate' => self::$stats['total_sent'] > 0 
                ? round(((self::$stats['total_sent'] - self::$stats['failed_count']) / self::$stats['total_sent']) * 100, 2) 
                : 100,
            'update_time' => time()
        ]);
    }

    /**
     * 重置推送统计
     */
    public static function resetStats()
    {
        self::$stats = [
            'total_sent' => 0,
            'game_start_sent' => 0,
            'countdown_sent' => 0,
            'game_end_sent' => 0,
            'game_result_sent' => 0,
            'win_info_sent' => 0,
            'failed_count' => 0,
            'last_reset' => time()
        ];
        
        Log::info('推送统计已重置');
    }

    /**
     * 获取推送类型列表
     * @return array
     */
    public static function getNotificationTypes()
    {
        return [
            self::TYPE_GAME_START => '游戏开始',
            self::TYPE_COUNTDOWN => '倒计时更新',
            self::TYPE_GAME_END => '游戏结束',
            self::TYPE_GAME_RESULT => '开奖结果',
            self::TYPE_WIN_INFO => '中奖信息'
        ];
    }

    /**
     * 测试推送功能
     * @param int $tableId
     * @return array
     */
    public static function testNotifications($tableId)
    {
        $results = [];
        
        try {
            // 测试游戏开始推送
            $results['game_start'] = self::sendGameStart($tableId, [
                'game_number' => 'TEST_' . time(),
                'round_number' => 1,
                'countdown' => 30
            ]);
            
            // 测试倒计时推送
            $results['countdown'] = self::sendCountdown($tableId, [
                'game_number' => 'TEST_' . time(),
                'remaining_time' => 10,
                'total_time' => 30
            ]);
            
            // 测试游戏结束推送
            $results['game_end'] = self::sendGameEnd($tableId, [
                'game_number' => 'TEST_' . time(),
                'end_time' => time()
            ]);
            
            // 测试开奖结果推送
            $results['game_result'] = self::sendGameResult($tableId, [
                'game_number' => 'TEST_' . time(),
                'round_number' => 1
            ], [
                'dice1' => 3,
                'dice2' => 4,
                'dice3' => 5,
                'winning_bets' => ['big', 'even']
            ]);
            
            Log::info('推送功能测试完成', [
                'table_id' => $tableId,
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
            Log::error('推送功能测试失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
    }
}