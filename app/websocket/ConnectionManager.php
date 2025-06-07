<?php

namespace app\websocket;

use Workerman\Connection\TcpConnection;
use think\facade\Log;

/**
 * WebSocket 连接管理器 - 完整版
 * 负责管理所有 WebSocket 连接的生命周期
 * 适配 PHP 7.3 + ThinkPHP6
 */
class ConnectionManager
{
    /**
     * 连接存储
     * 格式：['connection_id' => ['user_id' => xx, 'table_id' => xx, 'connection' => xx, ...]]
     */
    private static $connections = [];

    /**
     * 台桌连接映射
     * 格式：['table_id' => ['connection_id1', 'connection_id2', ...]]
     */
    private static $tableConnections = [];

    /**
     * 用户连接映射  
     * 格式：['user_id' => ['connection_id1', 'connection_id2', ...]]
     */
    private static $userConnections = [];

    /**
     * 连接统计信息
     */
    private static $stats = [
        'total_connections' => 0,
        'authenticated_users' => 0,
        'peak_connections' => 0,
        'total_messages_sent' => 0,
        'last_cleanup_time' => 0,
        'start_time' => 0
    ];

    /**
     * 初始化管理器
     */
    public static function init()
    {
        self::$connections = [];
        self::$tableConnections = [];
        self::$userConnections = [];
        self::$stats['start_time'] = time();
        self::$stats['last_cleanup_time'] = time();
        
        echo "[ConnectionManager] 连接管理器初始化完成\n";
    }

    /**
     * 添加新连接
     * @param TcpConnection $connection
     */
    public static function addConnection(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $remoteIp = $connection->getRemoteIp();
        
        // 存储连接信息
        self::$connections[$connectionId] = [
            'connection_id' => $connectionId,
            'user_id' => null,
            'table_id' => null,
            'connection' => $connection,
            'connect_time' => time(),
            'last_ping' => time(),
            'auth_status' => false,
            'remote_ip' => $remoteIp,
            'last_activity' => time(),
            'message_count' => 0,
            'auth_attempts' => 0
        ];

        // 更新统计
        self::$stats['total_connections']++;
        if (self::$stats['total_connections'] > self::$stats['peak_connections']) {
            self::$stats['peak_connections'] = self::$stats['total_connections'];
        }

        // 发送欢迎消息
        self::sendToConnection($connection, [
            'type' => 'welcome',
            'message' => '欢迎连接骰宝游戏服务',
            'server_time' => time(),
            'connection_info' => [
                'connection_id' => $connectionId,
                'server_version' => '1.0.0',
                'websocket_version' => '13'
            ]
        ]);

        echo "[" . date('Y-m-d H:i:s') . "] 新连接: {$connectionId} from {$remoteIp}\n";
    }

    /**
     * 移除连接
     * @param TcpConnection $connection
     */
    public static function removeConnection(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        
        if (!isset(self::$connections[$connectionId])) {
            return;
        }

        $connectionData = self::$connections[$connectionId];
        $userId = $connectionData['user_id'];
        $tableId = $connectionData['table_id'];

        // 从台桌中移除
        if ($tableId) {
            self::leaveTable($connectionId, $tableId);
        }

        // 从用户映射中移除
        if ($userId && isset(self::$userConnections[$userId])) {
            $key = array_search($connectionId, self::$userConnections[$userId]);
            if ($key !== false) {
                unset(self::$userConnections[$userId][$key]);
                self::$userConnections[$userId] = array_values(self::$userConnections[$userId]);
            }

            // 如果用户没有连接了，删除用户映射
            if (empty(self::$userConnections[$userId])) {
                unset(self::$userConnections[$userId]);
                if ($connectionData['auth_status']) {
                    self::$stats['authenticated_users']--;
                }
            }
        }

        // 记录连接时长
        $duration = time() - $connectionData['connect_time'];

        // 移除连接记录
        unset(self::$connections[$connectionId]);
        self::$stats['total_connections']--;
        
        echo "[" . date('Y-m-d H:i:s') . "] 连接移除: {$connectionId}, 时长: {$duration}秒\n";
    }

    /**
     * 用户认证
     * @param string $connectionId
     * @param int $userId
     */
    public static function authenticateUser($connectionId, $userId)
    {
        if (!isset(self::$connections[$connectionId])) {
            return false;
        }

        $connectionData = &self::$connections[$connectionId];

        // 如果用户已经认证为其他用户，先清除之前的认证
        if ($connectionData['auth_status'] && $connectionData['user_id'] && $connectionData['user_id'] !== $userId) {
            self::clearAuthentication($connectionId);
        }

        // 更新连接信息
        $connectionData['user_id'] = $userId;
        $connectionData['auth_status'] = true;
        $connectionData['auth_time'] = time();

        // 添加到用户连接映射
        if (!isset(self::$userConnections[$userId])) {
            self::$userConnections[$userId] = [];
            self::$stats['authenticated_users']++;
        }
        
        if (!in_array($connectionId, self::$userConnections[$userId])) {
            self::$userConnections[$userId][] = $connectionId;
        }

        echo "[" . date('Y-m-d H:i:s') . "] 用户认证: UserID {$userId}, Connection {$connectionId}\n";
        return true;
    }

    /**
     * 清除认证状态
     * @param string $connectionId
     */
    public static function clearAuthentication($connectionId)
    {
        if (!isset(self::$connections[$connectionId])) {
            return false;
        }

        $connectionData = &self::$connections[$connectionId];
        $userId = $connectionData['user_id'];

        if ($userId && isset(self::$userConnections[$userId])) {
            $key = array_search($connectionId, self::$userConnections[$userId]);
            if ($key !== false) {
                unset(self::$userConnections[$userId][$key]);
                self::$userConnections[$userId] = array_values(self::$userConnections[$userId]);
            }

            if (empty(self::$userConnections[$userId])) {
                unset(self::$userConnections[$userId]);
                self::$stats['authenticated_users']--;
            }
        }

        // 清除认证信息
        $connectionData['user_id'] = null;
        $connectionData['auth_status'] = false;
        unset($connectionData['auth_time']);

        return true;
    }

    /**
     * 加入台桌
     * @param string $connectionId
     * @param int $tableId
     */
    public static function joinTable($connectionId, $tableId)
    {
        if (!isset(self::$connections[$connectionId])) {
            return false;
        }

        $connectionData = &self::$connections[$connectionId];

        // 离开之前的台桌（如果有）
        $oldTableId = $connectionData['table_id'];
        if ($oldTableId) {
            self::leaveTable($connectionId, $oldTableId);
        }

        // 加入新台桌
        $connectionData['table_id'] = $tableId;
        $connectionData['join_table_time'] = time();

        // 添加到台桌连接映射
        if (!isset(self::$tableConnections[$tableId])) {
            self::$tableConnections[$tableId] = [];
        }
        
        if (!in_array($connectionId, self::$tableConnections[$tableId])) {
            self::$tableConnections[$tableId][] = $connectionId;
        }

        $userId = $connectionData['user_id'];
        echo "[" . date('Y-m-d H:i:s') . "] 用户加入台桌: UserID {$userId}, Table {$tableId}, Connection {$connectionId}\n";
        
        return true;
    }

    /**
     * 离开台桌
     * @param string $connectionId
     * @param int $tableId
     */
    public static function leaveTable($connectionId, $tableId)
    {
        // 从台桌连接映射中移除
        if (isset(self::$tableConnections[$tableId])) {
            $key = array_search($connectionId, self::$tableConnections[$tableId]);
            if ($key !== false) {
                unset(self::$tableConnections[$tableId][$key]);
                self::$tableConnections[$tableId] = array_values(self::$tableConnections[$tableId]);
            }

            // 如果台桌没有连接了，删除台桌映射
            if (empty(self::$tableConnections[$tableId])) {
                unset(self::$tableConnections[$tableId]);
            }
        }

        // 更新连接信息
        if (isset(self::$connections[$connectionId])) {
            $connectionData = &self::$connections[$connectionId];
            $userId = $connectionData['user_id'];
            $connectionData['table_id'] = null;
            unset($connectionData['join_table_time']);
            
            echo "[" . date('Y-m-d H:i:s') . "] 用户离开台桌: UserID {$userId}, Table {$tableId}, Connection {$connectionId}\n";
        }
    }

    /**
     * 更新连接活动时间
     * @param string $connectionId
     */
    public static function updatePing($connectionId)
    {
        if (isset(self::$connections[$connectionId])) {
            self::$connections[$connectionId]['last_ping'] = time();
            self::$connections[$connectionId]['last_activity'] = time();
        }
    }

    /**
     * 发送消息到指定连接
     * @param TcpConnection $connection
     * @param array $data
     * @return bool
     */
    public static function sendToConnection(TcpConnection $connection, array $data)
    {
        try {
            $message = json_encode($data, JSON_UNESCAPED_UNICODE);
            $result = $connection->send($message);
            
            // 更新统计
            self::$stats['total_messages_sent']++;
            
            // 更新连接的消息计数
            $connectionId = spl_object_hash($connection);
            if (isset(self::$connections[$connectionId])) {
                self::$connections[$connectionId]['message_count']++;
                self::$connections[$connectionId]['last_activity'] = time();
            }
            
            return $result !== false;
        } catch (\Exception $e) {
            $connectionId = spl_object_hash($connection);
            echo "[ERROR] 发送消息失败: Connection {$connectionId}, Error: " . $e->getMessage() . "\n";
            Log::error('发送消息失败', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'data_type' => $data['type'] ?? 'unknown'
            ]);
            return false;
        }
    }

    /**
     * 广播消息到台桌
     * @param int $tableId
     * @param array $data
     * @param array $excludeConnections
     * @return int 发送成功的连接数
     */
    public static function broadcastToTable($tableId, array $data, array $excludeConnections = [])
    {
        if (!isset(self::$tableConnections[$tableId])) {
            return 0;
        }

        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        $sentCount = 0;
        $failedCount = 0;
        
        foreach (self::$tableConnections[$tableId] as $connectionId) {
            if (in_array($connectionId, $excludeConnections)) {
                continue;
            }

            if (isset(self::$connections[$connectionId])) {
                try {
                    $connection = self::$connections[$connectionId]['connection'];
                    $result = $connection->send($message);
                    
                    if ($result !== false) {
                        $sentCount++;
                        // 更新连接统计
                        self::$connections[$connectionId]['message_count']++;
                        self::$connections[$connectionId]['last_activity'] = time();
                    } else {
                        $failedCount++;
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    echo "[ERROR] 台桌广播发送失败: Connection {$connectionId}, Error: " . $e->getMessage() . "\n";
                }
            }
        }

        // 更新统计
        self::$stats['total_messages_sent'] += $sentCount;

        if ($sentCount > 0 || $failedCount > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 台桌广播: Table {$tableId}, Type: " . ($data['type'] ?? 'unknown') . ", 成功: {$sentCount}, 失败: {$failedCount}\n";
        }
        
        return $sentCount;
    }

    /**
     * 发送消息到指定用户
     * @param int $userId
     * @param array $data
     * @return int 发送成功的连接数
     */
    public static function sendToUser($userId, array $data)
    {
        if (!isset(self::$userConnections[$userId])) {
            return 0;
        }

        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        $sentCount = 0;
        $failedCount = 0;
        
        foreach (self::$userConnections[$userId] as $connectionId) {
            if (isset(self::$connections[$connectionId])) {
                try {
                    $connection = self::$connections[$connectionId]['connection'];
                    $result = $connection->send($message);
                    
                    if ($result !== false) {
                        $sentCount++;
                        // 更新连接统计
                        self::$connections[$connectionId]['message_count']++;
                        self::$connections[$connectionId]['last_activity'] = time();
                    } else {
                        $failedCount++;
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    echo "[ERROR] 用户消息发送失败: Connection {$connectionId}, Error: " . $e->getMessage() . "\n";
                }
            }
        }

        // 更新统计
        self::$stats['total_messages_sent'] += $sentCount;

        if ($sentCount > 0 || $failedCount > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 用户消息: UserID {$userId}, Type: " . ($data['type'] ?? 'unknown') . ", 成功: {$sentCount}, 失败: {$failedCount}\n";
        }
        
        return $sentCount;
    }

    /**
     * 全平台广播
     * @param array $data
     * @return int 发送成功的连接数
     */
    public static function broadcastAll(array $data)
    {
        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        $sentCount = 0;
        $failedCount = 0;
        
        foreach (self::$connections as $connectionId => $connectionData) {
            try {
                $connection = $connectionData['connection'];
                $result = $connection->send($message);
                
                if ($result !== false) {
                    $sentCount++;
                    // 更新连接统计
                    self::$connections[$connectionId]['message_count']++;
                    self::$connections[$connectionId]['last_activity'] = time();
                } else {
                    $failedCount++;
                }
            } catch (\Exception $e) {
                $failedCount++;
                echo "[ERROR] 全平台广播发送失败: Connection {$connectionId}, Error: " . $e->getMessage() . "\n";
            }
        }

        // 更新统计
        self::$stats['total_messages_sent'] += $sentCount;

        echo "[" . date('Y-m-d H:i:s') . "] 全平台广播: Type: " . ($data['type'] ?? 'unknown') . ", 成功: {$sentCount}, 失败: {$failedCount}\n";
        return $sentCount;
    }

    /**
     * 清理无效连接
     */
    public static function cleanup()
    {
        $now = time();
        $timeout = 300; // 5分钟超时
        $authTimeout = 60; // 未认证连接1分钟超时
        $cleanedCount = 0;

        foreach (self::$connections as $connectionId => $connectionData) {
            $connection = $connectionData['connection'];
            $lastPing = $connectionData['last_ping'];
            $authStatus = $connectionData['auth_status'];
            $connectTime = $connectionData['connect_time'];
            
            $shouldCleanup = false;
            $reason = '';

            // 检查心跳超时
            if ($now - $lastPing > $timeout) {
                $shouldCleanup = true;
                $reason = 'ping timeout';
            }
            // 检查未认证连接超时
            elseif (!$authStatus && ($now - $connectTime > $authTimeout)) {
                $shouldCleanup = true;
                $reason = 'auth timeout';
            }

            if ($shouldCleanup) {
                try {
                    echo "[" . date('Y-m-d H:i:s') . "] 清理连接: {$connectionId}, 原因: {$reason}\n";
                    $connection->close();
                    self::removeConnection($connection);
                    $cleanedCount++;
                } catch (\Exception $e) {
                    // 忽略关闭异常，强制移除
                    self::removeConnection($connection);
                    $cleanedCount++;
                }
            }
        }

        // 更新清理时间
        self::$stats['last_cleanup_time'] = $now;

        if ($cleanedCount > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 清理完成: 移除 {$cleanedCount} 个无效连接\n";
        }
    }

    /**
     * 发送心跳
     */
    public static function sendHeartbeat()
    {
        $heartbeatData = [
            'type' => 'heartbeat',
            'timestamp' => time(),
            'online_count' => count(self::$connections),
            'server_time' => date('Y-m-d H:i:s')
        ];

        $sentCount = 0;
        foreach (self::$connections as $connectionData) {
            try {
                $connection = $connectionData['connection'];
                $result = self::sendToConnection($connection, $heartbeatData);
                if ($result) {
                    $sentCount++;
                }
            } catch (\Exception $e) {
                // 忽略发送失败的连接，会被清理程序处理
            }
        }

        if ($sentCount > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 心跳发送: {$sentCount} 个连接\n";
        }
    }

    /**
     * 获取连接信息
     * @param string $connectionId
     * @return array|null
     */
    public static function getConnection($connectionId)
    {
        return self::$connections[$connectionId] ?? null;
    }

    /**
     * 获取在线统计
     * @return array
     */
    public static function getOnlineStats()
    {
        $tableStats = [];
        foreach (self::$tableConnections as $tableId => $connections) {
            $tableStats[$tableId] = count($connections);
        }

        return [
            'total_connections' => count(self::$connections),
            'authenticated_users' => count(self::$userConnections),
            'unauthenticated_connections' => count(self::$connections) - count(self::$userConnections),
            'active_tables' => count(self::$tableConnections),
            'table_details' => $tableStats,
            'peak_connections' => self::$stats['peak_connections'],
            'total_messages_sent' => self::$stats['total_messages_sent'],
            'uptime' => time() - self::$stats['start_time'],
            'last_cleanup' => self::$stats['last_cleanup_time']
        ];
    }

    /**
     * 检查用户是否在线
     * @param int $userId
     * @return bool
     */
    public static function isUserOnline($userId)
    {
        return isset(self::$userConnections[$userId]) && !empty(self::$userConnections[$userId]);
    }

    /**
     * 获取台桌连接数
     * @param int $tableId
     * @return int
     */
    public static function getTableConnectionCount($tableId)
    {
        return count(self::$tableConnections[$tableId] ?? []);
    }

    /**
     * 获取台桌用户列表
     * @param int $tableId
     * @return array
     */
    public static function getTableUsers($tableId)
    {
        if (!isset(self::$tableConnections[$tableId])) {
            return [];
        }

        $users = [];
        foreach (self::$tableConnections[$tableId] as $connectionId) {
            if (isset(self::$connections[$connectionId])) {
                $userId = self::$connections[$connectionId]['user_id'];
                if ($userId && !in_array($userId, $users)) {
                    $users[] = $userId;
                }
            }
        }

        return $users;
    }

    /**
     * 获取用户连接数
     * @param int $userId
     * @return int
     */
    public static function getUserConnectionCount($userId)
    {
        return count(self::$userConnections[$userId] ?? []);
    }

    /**
     * 强制断开用户所有连接
     * @param int $userId
     * @param string $reason
     * @return int 断开的连接数
     */
    public static function forceDisconnectUser($userId, $reason = '管理员操作')
    {
        if (!isset(self::$userConnections[$userId])) {
            return 0;
        }

        $disconnectedCount = 0;
        $connections = self::$userConnections[$userId]; // 复制数组避免遍历时修改

        foreach ($connections as $connectionId) {
            if (isset(self::$connections[$connectionId])) {
                try {
                    $connection = self::$connections[$connectionId]['connection'];
                    
                    // 发送断开通知
                    self::sendToConnection($connection, [
                        'type' => 'force_disconnect',
                        'message' => $reason,
                        'timestamp' => time()
                    ]);
                    
                    // 强制关闭连接
                    $connection->close();
                    $disconnectedCount++;
                } catch (\Exception $e) {
                    // 忽略关闭异常
                    $disconnectedCount++;
                }
            }
        }

        Log::warning('强制断开用户连接', [
            'user_id' => $userId,
            'reason' => $reason,
            'disconnected_count' => $disconnectedCount
        ]);

        return $disconnectedCount;
    }

    /**
     * 清空台桌所有连接
     * @param int $tableId
     * @return int 清空的连接数
     */
    public static function clearTableConnections($tableId)
    {
        if (!isset(self::$tableConnections[$tableId])) {
            return 0;
        }

        $clearedCount = 0;
        $connections = self::$tableConnections[$tableId]; // 复制数组

        foreach ($connections as $connectionId) {
            if (isset(self::$connections[$connectionId])) {
                self::leaveTable($connectionId, $tableId);
                $clearedCount++;
            }
        }

        return $clearedCount;
    }

    /**
     * 获取详细统计信息
     * @return array
     */
    public static function getDetailedStats()
    {
        $stats = self::getOnlineStats();
        
        // 添加连接详情
        $connectionDetails = [];
        foreach (self::$connections as $connectionId => $data) {
            $connectionDetails[] = [
                'connection_id' => $connectionId,
                'user_id' => $data['user_id'],
                'table_id' => $data['table_id'],
                'auth_status' => $data['auth_status'],
                'connect_time' => $data['connect_time'],
                'last_ping' => $data['last_ping'],
                'remote_ip' => $data['remote_ip'],
                'message_count' => $data['message_count'],
                'duration' => time() - $data['connect_time']
            ];
        }

        $stats['connection_details'] = $connectionDetails;
        $stats['memory_usage'] = memory_get_usage(true);
        $stats['memory_peak'] = memory_get_peak_usage(true);
        
        return $stats;
    }

    /**
     * 重置统计信息
     */
    public static function resetStats()
    {
        self::$stats = array_merge(self::$stats, [
            'peak_connections' => count(self::$connections),
            'total_messages_sent' => 0,
            'start_time' => time(),
            'last_cleanup_time' => time()
        ]);
        
        echo "[ConnectionManager] 统计信息已重置\n";
    }
}