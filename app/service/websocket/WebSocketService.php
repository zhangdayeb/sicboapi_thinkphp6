<?php

namespace app\service\websocket;

use app\websocket\ConnectionManager;
use app\websocket\MessageHandler;
use app\websocket\AuthManager;
use app\websocket\TableManager;
use app\websocket\NotificationSender;
use think\facade\Log;
use think\facade\Cache;

/**
 * WebSocket 业务服务层
 * 提供高级业务功能和外部调用接口
 */
class WebSocketService
{
    /**
     * 游戏流程管理器
     * 负责完整的游戏流程控制
     */
    
    /**
     * 开始新游戏流程
     * @param int $tableId
     * @param array $gameConfig
     * @return array
     */
    public static function startGameFlow($tableId, array $gameConfig = [])
    {
        try {
            // 1. 开始新游戏
            $newGame = TableManager::startNewGame($tableId, $gameConfig);
            
            if (!$newGame) {
                return ['success' => false, 'error' => '开始游戏失败'];
            }

            // 2. 发送游戏开始通知
            $notifyCount = NotificationSender::sendGameStart($tableId, $newGame);
            
            // 3. 发送投注开始通知
            NotificationSender::sendBettingStart($tableId, $newGame);
            
            // 4. 启动倒计时
            self::startGameCountdown($tableId, $newGame);
            
            Log::info('游戏流程开始', [
                'table_id' => $tableId,
                'game_number' => $newGame['game_number'],
                'notify_count' => $notifyCount
            ]);
            
            return [
                'success' => true,
                'game_data' => $newGame,
                'notify_count' => $notifyCount
            ];
            
        } catch (\Exception $e) {
            Log::error('开始游戏流程失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 结束游戏流程
     * @param int $tableId
     * @param array $result
     * @return array
     */
    public static function endGameFlow($tableId, array $result)
    {
        try {
            // 1. 停止投注
            TableManager::stopBetting($tableId);
            
            // 2. 发送投注结束通知
            $gameStatus = TableManager::getGameStatus($tableId);
            NotificationSender::sendBettingEnd($tableId, $gameStatus['current_game']);
            
            // 3. 公布游戏结果
            TableManager::announceResult($tableId, $result);
            
            // 4. 发送开奖结果通知
            NotificationSender::sendGameResult($tableId, $gameStatus['current_game'], $result);
            
            // 5. 触发结算流程
            self::triggerSettlement($tableId, $gameStatus['current_game']['game_number']);
            
            // 6. 结束游戏
            TableManager::endGame($tableId);
            
            Log::info('游戏流程结束', [
                'table_id' => $tableId,
                'result' => $result
            ]);
            
            return ['success' => true, 'message' => '游戏流程结束'];
            
        } catch (\Exception $e) {
            Log::error('结束游戏流程失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 启动游戏倒计时
     * @param int $tableId
     * @param array $gameData
     */
    private static function startGameCountdown($tableId, array $gameData)
    {
        try {
            $countdownTime = $gameData['countdown'];
            $cacheKey = "game_countdown_{$tableId}";
            
            // 存储倒计时信息
            Cache::set($cacheKey, [
                'table_id' => $tableId,
                'game_number' => $gameData['game_number'],
                'start_time' => time(),
                'total_time' => $countdownTime,
                'status' => 'betting'
            ], $countdownTime + 60);
            
            // 这里可以启动定时任务或使用其他方式处理倒计时
            // 例如：推送到队列处理
            
        } catch (\Exception $e) {
            Log::error('启动游戏倒计时失败: ' . $e->getMessage());
        }
    }

    /**
     * 触发结算流程
     * @param int $tableId
     * @param string $gameNumber
     */
    private static function triggerSettlement($tableId, $gameNumber)
    {
        try {
            // 这里可以推送到队列进行异步结算
            // 或者调用结算服务
            
            Log::info('触发结算流程', [
                'table_id' => $tableId,
                'game_number' => $gameNumber
            ]);
            
        } catch (\Exception $e) {
            Log::error('触发结算流程失败: ' . $e->getMessage());
        }
    }

    /**
     * 用户管理服务
     */
    
    /**
     * 用户上线处理
     * @param int $userId
     * @param string $token
     * @param array $deviceInfo
     * @return array
     */
    public static function userOnline($userId, $token, array $deviceInfo = [])
    {
        try {
            // 1. 验证用户token
            $userInfo = AuthManager::validateToken($token, $userId);
            
            if (!$userInfo) {
                return ['success' => false, 'error' => '用户认证失败'];
            }

            // 2. 更新最后登录时间
            AuthManager::updateLastLogin($userId);
            
            // 3. 记录用户上线日志
            Log::info('用户WebSocket上线', [
                'user_id' => $userId,
                'device_info' => $deviceInfo,
                'user_info' => $userInfo
            ]);
            
            return [
                'success' => true,
                'user_info' => $userInfo,
                'message' => '用户上线成功'
            ];
            
        } catch (\Exception $e) {
            Log::error('用户上线处理失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 用户下线处理
     * @param int $userId
     * @return array
     */
    public static function userOffline($userId)
    {
        try {
            // 记录用户下线日志
            Log::info('用户WebSocket下线', ['user_id' => $userId]);
            
            return ['success' => true, 'message' => '用户下线处理完成'];
            
        } catch (\Exception $e) {
            Log::error('用户下线处理失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 检查用户在线状态
     * @param int $userId
     * @return bool
     */
    public static function isUserOnline($userId)
    {
        return ConnectionManager::isUserOnline($userId);
    }

    /**
     * 获取用户连接信息
     * @param int $userId
     * @return array
     */
    public static function getUserConnections($userId)
    {
        try {
            $onlineStats = ConnectionManager::getOnlineStats();
            $isOnline = self::isUserOnline($userId);
            
            return [
                'user_id' => $userId,
                'is_online' => $isOnline,
                'connection_count' => $isOnline ? 1 : 0, // 简化处理
                'last_activity' => time()
            ];
            
        } catch (\Exception $e) {
            Log::error('获取用户连接信息失败: ' . $e->getMessage());
            return [
                'user_id' => $userId,
                'is_online' => false,
                'connection_count' => 0,
                'last_activity' => null
            ];
        }
    }

    /**
     * 台桌管理服务
     */
    
    /**
     * 获取台桌详细信息
     * @param int $tableId
     * @param bool $includeStats
     * @return array
     */
    public static function getTableDetails($tableId, $includeStats = true)
    {
        try {
            // 1. 获取台桌基本信息
            $tableInfo = TableManager::getTableInfo($tableId);
            
            if (!$tableInfo) {
                return ['success' => false, 'error' => '台桌不存在'];
            }

            // 2. 获取游戏状态
            $gameStatus = TableManager::getGameStatus($tableId);
            
            // 3. 获取在线人数
            $onlineCount = ConnectionManager::getTableConnectionCount($tableId);
            
            $result = [
                'success' => true,
                'table_info' => $tableInfo,
                'game_status' => $gameStatus,
                'online_count' => $onlineCount
            ];

            // 4. 如果需要统计信息
            if ($includeStats) {
                $result['table_stats'] = TableManager::getTableStats($tableId);
                $result['table_history'] = TableManager::getTableHistory($tableId, 10);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('获取台桌详细信息失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 获取所有台桌状态
     * @return array
     */
    public static function getAllTablesStatus()
    {
        try {
            $tables = TableManager::getTableList(['status' => 1]);
            $tablesStatus = [];
            
            foreach ($tables as $table) {
                $tableId = $table['id'];
                $gameStatus = TableManager::getGameStatus($tableId);
                $onlineCount = ConnectionManager::getTableConnectionCount($tableId);
                
                $tablesStatus[] = [
                    'table_id' => $tableId,
                    'table_name' => $table['table_title'],
                    'status' => $table['status'],
                    'game_status' => $gameStatus['status'],
                    'online_count' => $onlineCount,
                    'current_game' => $gameStatus['current_game']
                ];
            }
            
            return [
                'success' => true,
                'tables' => $tablesStatus,
                'total_count' => count($tablesStatus)
            ];
            
        } catch (\Exception $e) {
            Log::error('获取所有台桌状态失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 消息发送服务
     */
    
    /**
     * 发送自定义消息给用户
     * @param int $userId
     * @param string $type
     * @param array $data
     * @param array $options
     * @return array
     */
    public static function sendMessageToUser($userId, $type, array $data, array $options = [])
    {
        try {
            if (!self::isUserOnline($userId)) {
                return ['success' => false, 'error' => '用户不在线'];
            }

            $message = array_merge([
                'type' => $type,
                'timestamp' => time()
            ], $data);

            $sentCount = ConnectionManager::sendToUser($userId, $message);
            
            return [
                'success' => true,
                'sent_count' => $sentCount,
                'message' => '消息发送成功'
            ];
            
        } catch (\Exception $e) {
            Log::error('发送用户消息失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 发送自定义消息给台桌
     * @param int $tableId
     * @param string $type
     * @param array $data
     * @param array $options
     * @return array
     */
    public static function sendMessageToTable($tableId, $type, array $data, array $options = [])
    {
        try {
            $message = array_merge([
                'type' => $type,
                'table_id' => $tableId,
                'timestamp' => time()
            ], $data);

            $excludeConnections = $options['exclude_connections'] ?? [];
            $sentCount = ConnectionManager::broadcastToTable($tableId, $message, $excludeConnections);
            
            return [
                'success' => true,
                'sent_count' => $sentCount,
                'message' => '台桌消息发送成功'
            ];
            
        } catch (\Exception $e) {
            Log::error('发送台桌消息失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 全平台广播消息
     * @param string $type
     * @param array $data
     * @param array $options
     * @return array
     */
    public static function broadcastMessage($type, array $data, array $options = [])
    {
        try {
            $message = array_merge([
                'type' => $type,
                'timestamp' => time()
            ], $data);

            $sentCount = ConnectionManager::broadcastAll($message);
            
            return [
                'success' => true,
                'sent_count' => $sentCount,
                'message' => '全平台广播成功'
            ];
            
        } catch (\Exception $e) {
            Log::error('全平台广播失败: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 系统监控服务
     */
    
    /**
     * 获取WebSocket服务状态
     * @return array
     */
    public static function getServiceStatus()
    {
        try {
            $onlineStats = ConnectionManager::getOnlineStats();
            
            return [
                'success' => true,
                'status' => 'running',
                'online_stats' => $onlineStats,
                'server_time' => date('Y-m-d H:i:s'),
                'uptime' => self::getServerUptime()
            ];
            
        } catch (\Exception $e) {
            Log::error('获取服务状态失败: ' . $e->getMessage());
            return [
                'success' => false,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取服务器运行时间
     * @return array
     */
    private static function getServerUptime()
    {
        try {
            // 从缓存获取启动时间
            $startTime = Cache::get('websocket_start_time');
            
            if ($startTime) {
                $uptime = time() - $startTime;
                return [
                    'seconds' => $uptime,
                    'formatted' => self::formatUptime($uptime)
                ];
            }
            
            return ['seconds' => 0, 'formatted' => '未知'];
            
        } catch (\Exception $e) {
            return ['seconds' => 0, 'formatted' => '获取失败'];
        }
    }

    /**
     * 格式化运行时间
     * @param int $seconds
     * @return string
     */
    private static function formatUptime($seconds)
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        return "{$days}天 {$hours}小时 {$minutes}分钟";
    }

    /**
     * 执行连接清理
     * @return array
     */
    public static function cleanupConnections()
    {
        try {
            ConnectionManager::cleanup();
            
            return [
                'success' => true,
                'message' => '连接清理完成'
            ];
            
        } catch (\Exception $e) {
            Log::error('连接清理失败: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取连接详情
     * @return array
     */
    public static function getConnectionDetails()
    {
        try {
            $stats = ConnectionManager::getOnlineStats();
            
            return [
                'success' => true,
                'connection_details' => $stats,
                'timestamp' => time()
            ];
            
        } catch (\Exception $e) {
            Log::error('获取连接详情失败: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 工具方法
     */
    
    /**
     * 验证用户权限
     * @param int $userId
     * @param string $permission
     * @param int|null $tableId
     * @return bool
     */
    public static function checkUserPermission($userId, $permission, $tableId = null)
    {
        return AuthManager::checkPermission($userId, $permission, $tableId);
    }

    /**
     * 强制用户下线
     * @param int $userId
     * @param string $reason
     * @return array
     */
    public static function forceUserOffline($userId, $reason = '管理员操作')
    {
        try {
            // 发送强制下线通知
            self::sendMessageToUser($userId, 'force_logout', [
                'reason' => $reason,
                'timestamp' => time()
            ]);
            
            // 撤销用户token
            AuthManager::revokeUserTokens($userId);
            
            return [
                'success' => true,
                'message' => '用户已强制下线'
            ];
            
        } catch (\Exception $e) {
            Log::error('强制用户下线失败: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 设置服务器启动时间
     * @param int|null $startTime
     */
    public static function setServerStartTime($startTime = null)
    {
        $startTime = $startTime ?: time();
        Cache::set('websocket_start_time', $startTime, 86400 * 30); // 缓存30天
    }

    /**
     * 健康检查
     * @return array
     */
    public static function healthCheck()
    {
        try {
            $stats = ConnectionManager::getOnlineStats();
            
            $health = [
                'status' => 'healthy',
                'connections' => $stats['total_connections'] ?? 0,
                'tables' => $stats['active_tables'] ?? 0,
                'users' => $stats['authenticated_users'] ?? 0,
                'timestamp' => time()
            ];
            
            // 简单的健康状态判断
            if ($health['connections'] > 1000) {
                $health['status'] = 'warning';
                $health['message'] = '连接数较高';
            }
            
            return [
                'success' => true,
                'health' => $health
            ];
            
        } catch (\Exception $e) {
            Log::error('健康检查失败: ' . $e->getMessage());
            return [
                'success' => false,
                'health' => [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                    'timestamp' => time()
                ]
            ];
        }
    }
}