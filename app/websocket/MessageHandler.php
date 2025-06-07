<?php

namespace app\websocket;

use Workerman\Connection\TcpConnection;
use app\websocket\events\AuthEventHandler;
use app\websocket\events\GameEventHandler;
use app\websocket\events\UserEventHandler;
use think\facade\Log;

/**
 * WebSocket 消息处理器
 * 负责解析和路由所有 WebSocket 消息
 */
class MessageHandler
{
    /**
     * 消息类型常量
     */
    const MSG_PING = 'ping';
    const MSG_AUTH = 'auth';
    const MSG_JOIN_TABLE = 'join_table';
    const MSG_LEAVE_TABLE = 'leave_table';
    const MSG_GAME_STATUS = 'game_status';
    const MSG_BET_UPDATE = 'bet_update';
    const MSG_USER_INFO = 'user_info';

    /**
     * 处理WebSocket消息
     * @param TcpConnection $connection
     * @param string $data
     */
    public static function handle(TcpConnection $connection, $data)
    {
        $connectionId = spl_object_hash($connection);
        
        // 解析JSON消息
        $message = json_decode($data, true);
        if (!$message || !isset($message['type'])) {
            self::sendError($connection, '消息格式错误');
            return;
        }

        $messageType = $message['type'];
        
        // 更新连接活动时间
        ConnectionManager::updatePing($connectionId);

        echo "收到消息: {$connectionId} -> {$messageType}\n";

        try {
            // 根据消息类型路由到对应处理器
            switch ($messageType) {
                case self::MSG_PING:
                    self::handlePing($connection, $message);
                    break;

                case self::MSG_AUTH:
                    AuthEventHandler::handle($connection, $message);
                    break;

                case self::MSG_JOIN_TABLE:
                case self::MSG_LEAVE_TABLE:
                case self::MSG_GAME_STATUS:
                    GameEventHandler::handle($connection, $message);
                    break;

                case self::MSG_BET_UPDATE:
                case self::MSG_USER_INFO:
                    UserEventHandler::handle($connection, $message);
                    break;

                default:
                    self::sendError($connection, '未知的消息类型: ' . $messageType);
                    break;
            }

        } catch (\Exception $e) {
            Log::error('消息处理异常', [
                'connection_id' => $connectionId,
                'message_type' => $messageType,
                'error' => $e->getMessage()
            ]);
            
            self::sendError($connection, '消息处理失败');
        }
    }

    /**
     * 处理心跳消息
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handlePing(TcpConnection $connection, array $message)
    {
        self::sendToConnection($connection, [
            'type' => 'pong',
            'timestamp' => time(),
            'message' => 'pong'
        ]);
    }

    /**
     * 发送成功响应
     * @param TcpConnection $connection
     * @param string $type
     * @param array $data
     * @param string $message
     */
    public static function sendSuccess(TcpConnection $connection, $type, array $data = [], $message = 'success')
    {
        $response = [
            'type' => $type,
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ];

        self::sendToConnection($connection, $response);
    }

    /**
     * 发送错误响应
     * @param TcpConnection $connection
     * @param string $message
     * @param string $type
     */
    public static function sendError(TcpConnection $connection, $message, $type = 'error')
    {
        $response = [
            'type' => $type,
            'success' => false,
            'message' => $message,
            'timestamp' => time()
        ];

        self::sendToConnection($connection, $response);
    }

    /**
     * 发送通知消息
     * @param TcpConnection $connection
     * @param string $title
     * @param string $content
     * @param array $extra
     */
    public static function sendNotification(TcpConnection $connection, $title, $content, array $extra = [])
    {
        $response = array_merge([
            'type' => 'notification',
            'title' => $title,
            'content' => $content,
            'timestamp' => time()
        ], $extra);

        self::sendToConnection($connection, $response);
    }

    /**
     * 发送消息到连接
     * @param TcpConnection $connection
     * @param array $data
     */
    public static function sendToConnection(TcpConnection $connection, array $data)
    {
        return ConnectionManager::sendToConnection($connection, $data);
    }

    /**
     * 验证连接是否已认证
     * @param TcpConnection $connection
     * @return bool
     */
    public static function isAuthenticated(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        return $connectionData && $connectionData['auth_status'] === true;
    }

    /**
     * 验证连接是否在台桌中
     * @param TcpConnection $connection
     * @return int|null 台桌ID或null
     */
    public static function getConnectionTableId(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        return $connectionData ? $connectionData['table_id'] : null;
    }

    /**
     * 获取连接的用户ID
     * @param TcpConnection $connection
     * @return int|null 用户ID或null
     */
    public static function getConnectionUserId(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        return $connectionData ? $connectionData['user_id'] : null;
    }

    /**
     * 广播台桌消息
     * @param int $tableId
     * @param array $data
     * @param array $excludeConnections
     */
    public static function broadcastToTable($tableId, array $data, array $excludeConnections = [])
    {
        return ConnectionManager::broadcastToTable($tableId, $data, $excludeConnections);
    }

    /**
     * 发送用户消息
     * @param int $userId
     * @param array $data
     */
    public static function sendToUser($userId, array $data)
    {
        return ConnectionManager::sendToUser($userId, $data);
    }

    /**
     * 全平台广播
     * @param array $data
     */
    public static function broadcastAll(array $data)
    {
        return ConnectionManager::broadcastAll($data);
    }

    /**
     * 验证消息参数
     * @param array $message
     * @param array $requiredFields
     * @return bool
     */
    public static function validateMessage(array $message, array $requiredFields)
    {
        foreach ($requiredFields as $field) {
            if (!isset($message[$field]) || empty($message[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * 记录消息日志
     * @param string $connectionId
     * @param string $type
     * @param array $message
     * @param string $level
     */
    public static function logMessage($connectionId, $type, array $message, $level = 'info')
    {
        Log::record([
            'connection_id' => $connectionId,
            'message_type' => $type,
            'message_data' => $message,
            'timestamp' => time()
        ], $level);
    }
}