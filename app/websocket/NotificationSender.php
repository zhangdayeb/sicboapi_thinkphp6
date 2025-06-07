<?php

namespace app\websocket;

use think\facade\Log;

/**
 * 通知发送器
 * 负责各种类型的WebSocket通知发送
 */
class NotificationSender
{
    /**
     * 通知类型常量
     */
    const TYPE_GAME_START = 'game_start';
    const TYPE_BETTING_START = 'betting_start';
    const TYPE_BETTING_END = 'betting_end';
    const TYPE_GAME_RESULT = 'game_result';
    const TYPE_SETTLEMENT = 'settlement';
    const TYPE_SYSTEM = 'system';
    const TYPE_USER = 'user';
    const TYPE_TABLE = 'table';
    const TYPE_BET = 'bet';

    /**
     * 通知优先级
     */
    const PRIORITY_LOW = 1;
    const PRIORITY_NORMAL = 2;
    const PRIORITY_HIGH = 3;
    const PRIORITY_URGENT = 4;

    /**
     * 发送游戏开始通知
     * @param int $tableId
     * @param array $gameData
     * @return int 发送成功的连接数
     */
    public static function sendGameStart($tableId, array $gameData)
    {
        try {
            $message = [
                'type' => self::TYPE_GAME_START,
                'table_id' => $tableId,
                'game_number' => $gameData['game_number'],
                'betting_time' => $gameData['countdown'],
                'round_number' => $gameData['round_number'] ?? 1,
                'start_time' => time(),
                'message' => '新一局游戏开始，请准备投注',
                'priority' => self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            self::logNotification(self::TYPE_GAME_START, $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送游戏开始通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送投注开始通知
     * @param int $tableId
     * @param array $gameData
     * @return int
     */
    public static function sendBettingStart($tableId, array $gameData)
    {
        try {
            $message = [
                'type' => self::TYPE_BETTING_START,
                'table_id' => $tableId,
                'game_number' => $gameData['game_number'],
                'countdown_time' => $gameData['countdown'],
                'end_time' => $gameData['betting_end_time'],
                'message' => '投注开始，倒计时 ' . $gameData['countdown'] . ' 秒',
                'priority' => self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            self::logNotification(self::TYPE_BETTING_START, $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送投注开始通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送投注结束通知
     * @param int $tableId
     * @param array $gameData
     * @return int
     */
    public static function sendBettingEnd($tableId, array $gameData)
    {
        try {
            $message = [
                'type' => self::TYPE_BETTING_END,
                'table_id' => $tableId,
                'game_number' => $gameData['game_number'],
                'end_time' => time(),
                'message' => '投注已截止，准备开奖',
                'priority' => self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            self::logNotification(self::TYPE_BETTING_END, $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送投注结束通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送开奖结果通知
     * @param int $tableId
     * @param array $gameData
     * @param array $result
     * @return int
     */
    public static function sendGameResult($tableId, array $gameData, array $result)
    {
        try {
            $totalPoints = $result['dice1'] + $result['dice2'] + $result['dice3'];
            $isBig = $totalPoints >= 11;
            $isOdd = $totalPoints % 2 == 1;
            $hasTriple = ($result['dice1'] == $result['dice2']) && ($result['dice2'] == $result['dice3']);
            $hasPair = !$hasTriple && (
                ($result['dice1'] == $result['dice2']) || 
                ($result['dice2'] == $result['dice3']) || 
                ($result['dice1'] == $result['dice3'])
            );

            $message = [
                'type' => self::TYPE_GAME_RESULT,
                'table_id' => $tableId,
                'game_number' => $gameData['game_number'],
                'result' => [
                    'dice1' => $result['dice1'],
                    'dice2' => $result['dice2'],
                    'dice3' => $result['dice3'],
                    'total_points' => $totalPoints,
                    'is_big' => $isBig,
                    'is_odd' => $isOdd,
                    'has_triple' => $hasTriple,
                    'has_pair' => $hasPair,
                    'triple_number' => $hasTriple ? $result['dice1'] : null
                ],
                'result_time' => time(),
                'message' => self::formatResultMessage($result),
                'priority' => self::PRIORITY_URGENT
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            self::logNotification(self::TYPE_GAME_RESULT, $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送开奖结果通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送结算通知
     * @param int $tableId
     * @param string $gameNumber
     * @param array $settlementData
     * @return int
     */
    public static function sendSettlement($tableId, $gameNumber, array $settlementData)
    {
        try {
            $message = [
                'type' => self::TYPE_SETTLEMENT,
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'settlement' => $settlementData,
                'settle_time' => time(),
                'message' => '本局结算完成',
                'priority' => self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            self::logNotification(self::TYPE_SETTLEMENT, $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送结算通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送个人结算通知
     * @param int $userId
     * @param array $personalSettlement
     * @return int
     */
    public static function sendPersonalSettlement($userId, array $personalSettlement)
    {
        try {
            $message = [
                'type' => 'personal_settlement',
                'settlement' => $personalSettlement,
                'settle_time' => time(),
                'message' => self::formatPersonalSettlementMessage($personalSettlement),
                'priority' => self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::sendToUser($userId, $message);
            
            self::logNotification('personal_settlement', $userId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送个人结算通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送系统通知
     * @param string $title
     * @param string $content
     * @param array $options
     * @return int
     */
    public static function sendSystemNotification($title, $content, array $options = [])
    {
        try {
            $message = [
                'type' => self::TYPE_SYSTEM,
                'title' => $title,
                'content' => $content,
                'level' => $options['level'] ?? 'info',
                'send_time' => time(),
                'expire_time' => $options['expire_time'] ?? null,
                'require_confirm' => $options['require_confirm'] ?? false,
                'priority' => $options['priority'] ?? self::PRIORITY_NORMAL
            ];

            // 如果指定了目标用户
            if (isset($options['target_users']) && is_array($options['target_users'])) {
                $sentCount = 0;
                foreach ($options['target_users'] as $userId) {
                    $sentCount += ConnectionManager::sendToUser($userId, $message);
                }
            } else {
                // 全平台广播
                $sentCount = ConnectionManager::broadcastAll($message);
            }
            
            self::logNotification(self::TYPE_SYSTEM, 'all', $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送系统通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送用户通知
     * @param int $userId
     * @param string $title
     * @param string $content
     * @param array $options
     * @return int
     */
    public static function sendUserNotification($userId, $title, $content, array $options = [])
    {
        try {
            $message = [
                'type' => self::TYPE_USER,
                'title' => $title,
                'content' => $content,
                'level' => $options['level'] ?? 'info',
                'send_time' => time(),
                'auto_close' => $options['auto_close'] ?? 5000,
                'require_confirm' => $options['require_confirm'] ?? false,
                'priority' => $options['priority'] ?? self::PRIORITY_NORMAL
            ];

            $sentCount = ConnectionManager::sendToUser($userId, $message);
            
            self::logNotification(self::TYPE_USER, $userId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送用户通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送台桌通知
     * @param int $tableId
     * @param string $title
     * @param string $content
     * @param array $options
     * @return int
     */
    public static function sendTableNotification($tableId, $title, $content, array $options = [])
    {
        try {
            $message = [
                'type' => self::TYPE_TABLE,
                'table_id' => $tableId,
                'title' => $title,
                'content' => $content,
                'level' => $options['level'] ?? 'info',
                'send_time' => time(),
                'priority' => $options['priority'] ?? self::PRIORITY_NORMAL
            ];

            $excludeConnections = $options['exclude_connections'] ?? [];
            $sentCount = ConnectionManager::broadcastToTable($tableId, $message, $excludeConnections);
            
            self::logNotification(self::TYPE_TABLE, $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送台桌通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送投注相关通知
     * @param int $tableId
     * @param string $eventType
     * @param array $data
     * @return int
     */
    public static function sendBetNotification($tableId, $eventType, array $data)
    {
        try {
            $message = [
                'type' => self::TYPE_BET,
                'event_type' => $eventType,
                'table_id' => $tableId,
                'data' => $data,
                'timestamp' => time(),
                'priority' => self::PRIORITY_NORMAL
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            self::logNotification(self::TYPE_BET, $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送投注通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送倒计时更新
     * @param int $tableId
     * @param int $countdown
     * @param string $status
     * @return int
     */
    public static function sendCountdownUpdate($tableId, $countdown, $status)
    {
        try {
            $message = [
                'type' => 'countdown_update',
                'table_id' => $tableId,
                'countdown' => $countdown,
                'status' => $status,
                'timestamp' => time(),
                'priority' => self::PRIORITY_NORMAL
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            // 倒计时更新不记录详细日志，避免日志过多
            if ($countdown % 10 == 0 || $countdown <= 5) {
                self::logNotification('countdown_update', $tableId, ['countdown' => $countdown], $sentCount);
            }
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送倒计时更新失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送大奖通知
     * @param int $tableId
     * @param array $winData
     * @return int
     */
    public static function sendBigWinNotification($tableId, array $winData)
    {
        try {
            $winAmount = $winData['win_amount'] ?? 0;
            
            $message = [
                'type' => 'big_win',
                'table_id' => $tableId,
                'win_amount' => $winAmount,
                'game_number' => $winData['game_number'] ?? '',
                'message' => "恭喜有玩家获得大奖 ¥{$winAmount}！",
                'animation' => 'fireworks',
                'priority' => self::PRIORITY_URGENT
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            self::logNotification('big_win', $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送大奖通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送维护通知
     * @param string $title
     * @param string $content
     * @param array $options
     * @return int
     */
    public static function sendMaintenanceNotification($title, $content, array $options = [])
    {
        try {
            $message = [
                'type' => 'maintenance',
                'title' => $title,
                'content' => $content,
                'start_time' => $options['start_time'] ?? time(),
                'end_time' => $options['end_time'] ?? null,
                'affected_tables' => $options['affected_tables'] ?? null,
                'require_confirm' => true,
                'priority' => self::PRIORITY_URGENT
            ];

            $sentCount = ConnectionManager::broadcastAll($message);
            
            self::logNotification('maintenance', 'all', $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送维护通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送余额更新通知
     * @param int $userId
     * @param array $balanceData
     * @return int
     */
    public static function sendBalanceUpdate($userId, array $balanceData)
    {
        try {
            $message = [
                'type' => 'balance_update',
                'user_id' => $userId,
                'balance' => $balanceData['balance'],
                'change_amount' => $balanceData['change_amount'] ?? 0,
                'change_type' => $balanceData['change_type'] ?? '',
                'reason' => $balanceData['reason'] ?? '',
                'timestamp' => time(),
                'priority' => self::PRIORITY_HIGH
            ];

            $sentCount = ConnectionManager::sendToUser($userId, $message);
            
            self::logNotification('balance_update', $userId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送余额更新通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送连胜/连败通知
     * @param int $tableId
     * @param array $streakData
     * @return int
     */
    public static function sendStreakNotification($tableId, array $streakData)
    {
        try {
            $message = [
                'type' => 'streak_notification',
                'table_id' => $tableId,
                'streak_type' => $streakData['type'], // 'big', 'small', 'odd', 'even'
                'streak_count' => $streakData['count'],
                'message' => self::formatStreakMessage($streakData),
                'timestamp' => time(),
                'priority' => self::PRIORITY_NORMAL
            ];

            $sentCount = ConnectionManager::broadcastToTable($tableId, $message);
            
            self::logNotification('streak_notification', $tableId, $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送连胜通知失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 批量发送通知
     * @param array $notifications
     * @return array
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
                    case 'user':
                        if (is_numeric($target)) {
                            $sentCount = ConnectionManager::sendToUser($target, $data);
                        }
                        break;
                        
                    case 'table':
                        if (is_numeric($target)) {
                            $sentCount = ConnectionManager::broadcastToTable($target, $data);
                        }
                        break;
                        
                    case 'all':
                        $sentCount = ConnectionManager::broadcastAll($data);
                        break;
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
                
                Log::error('批量发送通知失败: ' . $e->getMessage(), [
                    'index' => $index,
                    'notification' => $notification
                ]);
            }
        }
        
        return $results;
    }

    /**
     * 格式化开奖结果消息
     * @param array $result
     * @return string
     */
    private static function formatResultMessage(array $result)
    {
        $dice = "{$result['dice1']}-{$result['dice2']}-{$result['dice3']}";
        $total = $result['dice1'] + $result['dice2'] + $result['dice3'];
        $bigSmall = $total >= 11 ? '大' : '小';
        $oddEven = $total % 2 == 1 ? '单' : '双';
        
        $message = "开奖结果：{$dice}，总点数：{$total}，{$bigSmall}/{$oddEven}";
        
        // 检查特殊结果
        if (($result['dice1'] == $result['dice2']) && ($result['dice2'] == $result['dice3'])) {
            $message .= "，三同号 {$result['dice1']}";
        } elseif (($result['dice1'] == $result['dice2']) || ($result['dice2'] == $result['dice3']) || ($result['dice1'] == $result['dice3'])) {
            $message .= "，包含对子";
        }
        
        return $message;
    }

    /**
     * 格式化个人结算消息
     * @param array $settlement
     * @return string
     */
    private static function formatPersonalSettlementMessage(array $settlement)
    {
        $winAmount = $settlement['total_win_amount'] ?? 0;
        $betAmount = $settlement['total_bet_amount'] ?? 0;
        $winCount = $settlement['win_count'] ?? 0;
        $betCount = $settlement['bet_count'] ?? 0;
        
        if ($winAmount > 0) {
            $profit = $winAmount - $betAmount;
            $profitText = $profit > 0 ? "盈利 ¥{$profit}" : "亏损 ¥" . abs($profit);
            return "结算完成：投注 {$betCount} 笔，中奖 {$winCount} 笔，{$profitText}";
        } else {
            return "结算完成：投注 {$betCount} 笔，未中奖";
        }
    }

    /**
     * 格式化连胜消息
     * @param array $streakData
     * @return string
     */
    private static function formatStreakMessage(array $streakData)
    {
        $type = $streakData['type'];
        $count = $streakData['count'];
        
        $typeNames = [
            'big' => '大',
            'small' => '小',
            'odd' => '单',
            'even' => '双'
        ];
        
        $typeName = $typeNames[$type] ?? $type;
        
        return "连续 {$count} 局开 {$typeName}！";
    }

    /**
     * 记录通知日志
     * @param string $type
     * @param mixed $target
     * @param array $message
     * @param int $sentCount
     */
    private static function logNotification($type, $target, array $message, $sentCount)
    {
        try {
            Log::info('WebSocket通知发送', [
                'type' => $type,
                'target' => $target,
                'message_type' => $message['type'] ?? 'unknown',
                'sent_count' => $sentCount,
                'priority' => $message['priority'] ?? self::PRIORITY_NORMAL,
                'timestamp' => time()
            ]);
            
        } catch (\Exception $e) {
            // 忽略日志记录错误
        }
    }

    /**
     * 获取通知统计信息
     * @return array
     */
    public static function getNotificationStats()
    {
        try {
            $onlineStats = ConnectionManager::getOnlineStats();
            
            return [
                'online_connections' => $onlineStats['total_connections'] ?? 0,
                'active_tables' => $onlineStats['active_tables'] ?? 0,
                'authenticated_users' => $onlineStats['authenticated_users'] ?? 0,
                'update_time' => time()
            ];
            
        } catch (\Exception $e) {
            Log::error('获取通知统计失败: ' . $e->getMessage());
            return [
                'online_connections' => 0,
                'active_tables' => 0,
                'authenticated_users' => 0,
                'update_time' => time()
            ];
        }
    }

    /**
     * 测试连接
     * @param int $tableId
     * @return array
     */
    public static function testConnection($tableId = null)
    {
        try {
            $testMessage = [
                'type' => 'connection_test',
                'message' => 'WebSocket连接测试',
                'timestamp' => time(),
                'test_id' => uniqid(),
                'priority' => self::PRIORITY_LOW
            ];

            if ($tableId) {
                $sentCount = ConnectionManager::broadcastToTable($tableId, $testMessage);
                $target = "table_{$tableId}";
            } else {
                $sentCount = ConnectionManager::broadcastAll($testMessage);
                $target = 'all';
            }

            return [
                'success' => true,
                'target' => $target,
                'sent_count' => $sentCount,
                'message' => '连接测试完成',
                'test_id' => $testMessage['test_id']
            ];
            
        } catch (\Exception $e) {
            Log::error('连接测试失败: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => '连接测试失败'
            ];
        }
    }

    /**
     * 发送心跳检测消息
     * @param int|null $tableId 指定台桌ID，null表示全平台
     * @return int
     */
    public static function sendHeartbeat($tableId = null)
    {
        try {
            $heartbeatMessage = [
                'type' => 'heartbeat',
                'timestamp' => time(),
                'server_status' => 'running',
                'priority' => self::PRIORITY_LOW
            ];

            if ($tableId) {
                $sentCount = ConnectionManager::broadcastToTable($tableId, $heartbeatMessage);
            } else {
                $sentCount = ConnectionManager::broadcastAll($heartbeatMessage);
            }
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送心跳检测失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 发送紧急通知
     * @param string $title
     * @param string $content
     * @param array $options
     * @return int
     */
    public static function sendEmergencyNotification($title, $content, array $options = [])
    {
        try {
            $message = [
                'type' => 'emergency',
                'title' => $title,
                'content' => $content,
                'level' => 'urgent',
                'require_confirm' => true,
                'auto_close' => false,
                'send_time' => time(),
                'priority' => self::PRIORITY_URGENT
            ];

            $sentCount = ConnectionManager::broadcastAll($message);
            
            self::logNotification('emergency', 'all', $message, $sentCount);
            
            return $sentCount;
            
        } catch (\Exception $e) {
            Log::error('发送紧急通知失败: ' . $e->getMessage());
            return 0;
        }
    }
}