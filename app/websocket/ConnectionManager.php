<?php

namespace app\websocket;

use Workerman\Connection\TcpConnection;
use think\facade\Log;

/**
 * WebSocket 连接管理器
 * 负责管理所有 WebSocket 连接的生命周期
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
     * 初始化管理器
     */
    public static function init()
    {
        self::$connections = [];
        self::$tableConnections = [];
        self::$userConnections = [];
        
        echo "连接管理器初始化完成\n";
    }

    /**
     * 添加新连接
     * @param TcpConnection $connection
     */
    public static function addConnection(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        
        // 存储连接信息
        self::$connections[$connectionId] = [
            'user_id' => null,
            'table_id' => null,
            'connection' => $connection,
            'connect_time' => time(),
            'last_ping' => time(),
            'auth_status' => false,
            'remote_ip' => $connection->getRemoteIp()
        ];

        // 发送欢迎消息
        self::sendToConnection($connection, [
            'type' => 'welcome',
            'message' => '欢迎连接骰宝游戏服务',
            'connection_id' => $connectionId,
            'server_time' => time()
        ]);

        echo "连接已添加: {$connectionId}\n";
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
            }
        }

        // 移除连接记录
        unset(self::$connections[$connectionId]);
        
        echo "连接已移除: {$connectionId}\n";
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

        // 更新连接信息
        self::$connections[$connectionId]['user_id'] = $userId;
        self::$connections[$connectionId]['auth_status'] = true;

        // 添加到用户连接映射
        if (!isset(self::$userConnections[$userId])) {
            self::$userConnections[$userId] = [];
        }
        self::$userConnections[$userId][] = $connectionId;

        echo "用户认证成功: UserID {$userId}, Connection {$connectionId}\n";
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

        // 离开之前的台桌（如果有）
        $oldTableId = self::$connections[$connectionId]['table_id'];
        if ($oldTableId) {
            self::leaveTable($connectionId, $oldTableId);
        }

        // 加入新台桌
        self::$connections[$connectionId]['table_id'] = $tableId;

        // 添加到台桌连接映射
        if (!isset(self::$tableConnections[$tableId])) {
            self::$tableConnections[$tableId] = [];
        }
        self::$tableConnections[$tableId][] = $connectionId;

        $userId = self::$connections[$connectionId]['user_id'];
        echo "用户加入台桌: UserID {$userId}, Table {$tableId}\n";
        
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
            self::$connections[$connectionId]['table_id'] = null;
            $userId = self::$connections[$connectionId]['user_id'];
            echo "用户离开台桌: UserID {$userId}, Table {$tableId}\n";
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
        }
    }

    /**
     * 发送消息到指定连接
     * @param TcpConnection $connection
     * @param array $data
     */
    public static function sendToConnection(TcpConnection $connection, array $data)
    {
        try {
            $message = json_encode($data, JSON_UNESCAPED_UNICODE);
            $connection->send($message);
            return true;
        } catch (\Exception $e) {
            echo "发送消息失败: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * 广播消息到台桌
     * @param int $tableId
     * @param array $data
     * @param array $excludeConnections
     */
    public static function broadcastToTable($tableId, array $data, array $excludeConnections = [])
    {
        if (!isset(self::$tableConnections[$tableId])) {
            return 0;
        }

        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        $sentCount = 0;
        
        foreach (self::$tableConnections[$tableId] as $connectionId) {
            if (in_array($connectionId, $excludeConnections)) {
                continue;
            }

            if (isset(self::$connections[$connectionId])) {
                try {
                    $connection = self::$connections[$connectionId]['connection'];
                    $connection->send($message);
                    $sentCount++;
                } catch (\Exception $e) {
                    echo "台桌广播发送失败: " . $e->getMessage() . "\n";
                }
            }
        }

        echo "台桌广播: Table {$tableId}, Type: " . ($data['type'] ?? 'unknown') . ", 发送数: {$sentCount}\n";
        return $sentCount;
    }

    /**
     * 发送消息到指定用户
     * @param int $userId
     * @param array $data
     */
    public static function sendToUser($userId, array $data)
    {
        if (!isset(self::$userConnections[$userId])) {
            return 0;
        }

        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        $sentCount = 0;
        
        foreach (self::$userConnections[$userId] as $connectionId) {
            if (isset(self::$connections[$connectionId])) {
                try {
                    $connection = self::$connections[$connectionId]['connection'];
                    $connection->send($message);
                    $sentCount++;
                } catch (\Exception $e) {
                    echo "用户消息发送失败: " . $e->getMessage() . "\n";
                }
            }
        }

        echo "用户消息: UserID {$userId}, Type: " . ($data['type'] ?? 'unknown') . ", 发送数: {$sentCount}\n";
        return $sentCount;
    }

    /**
     * 全平台广播
     * @param array $data
     */
    public static function broadcastAll(array $data)
    {
        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        $sentCount = 0;
        
        foreach (self::$connections as $connectionId => $connectionData) {
            try {
                $connection = $connectionData['connection'];
                $connection->send($message);
                $sentCount++;
            } catch (\Exception $e) {
                echo "全平台广播发送失败: " . $e->getMessage() . "\n";
            }
        }

        echo "全平台广播: Type: " . ($data['type'] ?? 'unknown') . ", 发送数: {$sentCount}\n";
        return $sentCount;
    }

    /**
     * 清理无效连接
     */
    public static function cleanup()
    {
        $now = time();
        $timeout = 120; // 2分钟超时
        $cleanedCount = 0;

        foreach (self::$connections as $connectionId => $connectionData) {
            if ($now - $connectionData['last_ping'] > $timeout) {
                try {
                    $connection = $connectionData['connection'];
                    $connection->close();
                } catch (\Exception $e) {
                    // 忽略关闭异常
                }
                self::removeConnection($connection);
                $cleanedCount++;
            }
        }

        if ($cleanedCount > 0) {
            echo "清理无效连接: {$cleanedCount} 个\n";
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
            'online_count' => count(self::$connections)
        ];

        $sentCount = 0;
        foreach (self::$connections as $connectionData) {
            try {
                $connection = $connectionData['connection'];
                self::sendToConnection($connection, $heartbeatData);
                $sentCount++;
            } catch (\Exception $e) {
                // 忽略发送失败的连接，会被清理程序处理
            }
        }

        if ($sentCount > 0) {
            echo "心跳发送: {$sentCount} 个连接\n";
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
        return [
            'total_connections' => count(self::$connections),
            'authenticated_users' => count(self::$userConnections),
            'active_tables' => count(self::$tableConnections),
            'table_details' => array_map('count', self::$tableConnections)
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
}