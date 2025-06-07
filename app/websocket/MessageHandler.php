<?php

namespace app\websocket;

use Workerman\Connection\TcpConnection;
use app\websocket\events\AuthEventHandler;
use app\websocket\events\GameEventHandler;
use app\websocket\events\UserEventHandler;
use think\facade\Log;

/**
 * WebSocket 消息处理器 - 完整版
 * 负责解析和路由所有 WebSocket 消息
 * 适配 PHP 7.3 + ThinkPHP6
 */
class MessageHandler
{
    /**
     * 消息类型常量
     */
    const MSG_PING = 'ping';
    const MSG_PONG = 'pong';
    const MSG_AUTH = 'auth';
    const MSG_LOGOUT = 'logout';
    const MSG_JOIN_TABLE = 'join_table';
    const MSG_LEAVE_TABLE = 'leave_table';
    const MSG_GAME_STATUS = 'game_status';
    const MSG_GET_BALANCE = 'get_balance';
    const MSG_GET_BET_HISTORY = 'get_bet_history';

    /**
     * 消息处理统计
     */
    private static $stats = [
        'total_messages' => 0,
        'auth_messages' => 0,
        'game_messages' => 0,
        'user_messages' => 0,
        'ping_messages' => 0,
        'error_messages' => 0,
        'invalid_messages' => 0,
        'start_time' => 0
    ];

    /**
     * 最大消息长度（字节）
     */
    const MAX_MESSAGE_LENGTH = 8192; // 8KB

    /**
     * 消息频率限制（每分钟）
     */
    const MESSAGE_RATE_LIMIT = 60;

    /**
     * 初始化消息处理器
     */
    public static function init()
    {
        self::$stats['start_time'] = time();
        echo "[MessageHandler] 消息处理器初始化完成\n";
    }

    /**
     * 处理WebSocket消息
     * @param TcpConnection $connection
     * @param string $data
     */
    public static function handle(TcpConnection $connection, $data)
    {
        $connectionId = spl_object_hash($connection);
        
        try {
            // 更新统计
            self::$stats['total_messages']++;
            
            // 检查消息长度
            if (strlen($data) > self::MAX_MESSAGE_LENGTH) {
                self::handleError($connection, 'MESSAGE_TOO_LARGE', '消息长度超过限制');
                return;
            }

            // 检查消息频率
            if (!self::checkMessageRate($connectionId)) {
                self::handleError($connection, 'RATE_LIMIT_EXCEEDED', '消息发送过于频繁');
                return;
            }

            // 解析JSON消息
            $message = json_decode($data, true);
            if (!$message || !is_array($message)) {
                self::handleError($connection, 'INVALID_JSON', '消息格式错误');
                return;
            }

            // 检查消息类型
            if (!isset($message['type']) || empty($message['type'])) {
                self::handleError($connection, 'MISSING_TYPE', '缺少消息类型');
                return;
            }

            $messageType = $message['type'];
            
            // 更新连接活动时间
            ConnectionManager::updatePing($connectionId);

            // 记录调试信息（仅在调试模式下）
            if (defined('APP_DEBUG') && APP_DEBUG) {
                echo "[" . date('Y-m-d H:i:s') . "] 收到消息: {$connectionId} -> {$messageType}\n";
            }

            // 验证基础消息格式
            if (!self::validateMessageFormat($message)) {
                self::handleError($connection, 'INVALID_FORMAT', '消息格式验证失败');
                return;
            }

            // 根据消息类型路由到对应处理器
            switch ($messageType) {
                case self::MSG_PING:
                    self::handlePing($connection, $message);
                    break;

                case self::MSG_PONG:
                    self::handlePong($connection, $message);
                    break;

                case self::MSG_AUTH:
                case self::MSG_LOGOUT:
                    self::$stats['auth_messages']++;
                    AuthEventHandler::handle($connection, $message);
                    break;

                case self::MSG_JOIN_TABLE:
                case self::MSG_LEAVE_TABLE:
                case self::MSG_GAME_STATUS:
                    self::$stats['game_messages']++;
                    // 这些消息需要先认证
                    if (!self::isAuthenticated($connection)) {
                        self::handleError($connection, 'AUTH_REQUIRED', '请先进行身份认证');
                        return;
                    }
                    GameEventHandler::handle($connection, $message);
                    break;

                case self::MSG_GET_BALANCE:
                case self::MSG_GET_BET_HISTORY:
                    self::$stats['user_messages']++;
                    // 这些消息需要先认证
                    if (!self::isAuthenticated($connection)) {
                        self::handleError($connection, 'AUTH_REQUIRED', '请先进行身份认证');
                        return;
                    }
                    UserEventHandler::handle($connection, $message);
                    break;

                default:
                    self::handleError($connection, 'UNKNOWN_TYPE', '未知的消息类型: ' . $messageType);
                    break;
            }

        } catch (\Exception $e) {
            self::$stats['error_messages']++;
            
            Log::error('消息处理异常', [
                'connection_id' => $connectionId,
                'data' => substr($data, 0, 500),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            self::handleError($connection, 'SERVER_ERROR', '消息处理失败');
        }
    }

    /**
     * 处理心跳消息
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handlePing(TcpConnection $connection, array $message)
    {
        self::$stats['ping_messages']++;
        
        $response = [
            'type' => self::MSG_PONG,
            'timestamp' => time(),
            'server_time' => date('Y-m-d H:i:s'),
            'message' => 'pong'
        ];

        // 如果ping消息包含数据，原样返回
        if (isset($message['data'])) {
            $response['data'] = $message['data'];
        }

        self::sendToConnection($connection, $response);
    }

    /**
     * 处理心跳响应消息
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handlePong(TcpConnection $connection, array $message)
    {
        // 客户端回复的pong消息，只需要更新活动时间即可
        // ConnectionManager::updatePing() 已经在上层调用过了
        
        // 记录延迟信息（如果有）
        if (isset($message['timestamp'])) {
            $latency = time() - (int)$message['timestamp'];
            if ($latency >= 0 && $latency <= 60) {
                $connectionId = spl_object_hash($connection);
                $connectionData = ConnectionManager::getConnection($connectionId);
                if ($connectionData) {
                    // 这里可以记录连接延迟信息
                }
            }
        }
    }

    /**
     * 检查消息频率限制
     * @param string $connectionId
     * @return bool
     */
    private static function checkMessageRate($connectionId)
    {
        static $messageCounters = [];
        static $lastCleanup = 0;
        
        $currentTime = time();
        $timeWindow = 60; // 60秒窗口
        
        // 每5分钟清理一次过期计数器
        if ($currentTime - $lastCleanup > 300) {
            $messageCounters = [];
            $lastCleanup = $currentTime;
        }

        // 初始化计数器
        if (!isset($messageCounters[$connectionId])) {
            $messageCounters[$connectionId] = [
                'count' => 0,
                'window_start' => $currentTime
            ];
        }

        $counter = &$messageCounters[$connectionId];

        // 检查是否需要重置窗口
        if ($currentTime - $counter['window_start'] >= $timeWindow) {
            $counter['count'] = 0;
            $counter['window_start'] = $currentTime;
        }

        // 增加计数
        $counter['count']++;

        // 检查是否超过限制
        return $counter['count'] <= self::MESSAGE_RATE_LIMIT;
    }

    /**
     * 验证消息格式
     * @param array $message
     * @return bool
     */
    private static function validateMessageFormat(array $message)
    {
        // 检查必需字段
        if (!isset($message['type'])) {
            return false;
        }

        // 检查消息类型格式
        if (!is_string($message['type']) || empty($message['type'])) {
            return false;
        }

        // 检查时间戳（如果存在）
        if (isset($message['timestamp'])) {
            if (!is_numeric($message['timestamp'])) {
                return false;
            }
            
            $timestamp = (int)$message['timestamp'];
            $currentTime = time();
            
            // 时间戳不能太旧或太新（5分钟容差）
            if (abs($currentTime - $timestamp) > 300) {
                return false;
            }
        }

        // 检查数据字段（如果存在）
        if (isset($message['data']) && !is_array($message['data'])) {
            return false;
        }

        return true;
    }

    /**
     * 处理错误消息
     * @param TcpConnection $connection
     * @param string $errorCode
     * @param string $errorMessage
     */
    private static function handleError(TcpConnection $connection, $errorCode, $errorMessage)
    {
        self::$stats['invalid_messages']++;
        
        $connectionId = spl_object_hash($connection);
        
        // 记录错误日志
        Log::warning('WebSocket消息处理错误', [
            'connection_id' => $connectionId,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'remote_ip' => $connection->getRemoteIp()
        ]);

        // 发送错误响应
        self::sendError($connection, $errorMessage, $errorCode);
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
     * @param string $errorCode
     */
    public static function sendError(TcpConnection $connection, $message, $errorCode = 'ERROR')
    {
        $response = [
            'type' => 'error',
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
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
     * @return bool
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
            if (!isset($message[$field]) || (is_string($message[$field]) && empty($message[$field]))) {
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

    /**
     * 获取消息处理统计
     * @return array
     */
    public static function getStats()
    {
        $runtime = time() - self::$stats['start_time'];
        
        return array_merge(self::$stats, [
            'runtime_seconds' => $runtime,
            'messages_per_minute' => $runtime > 0 ? round((self::$stats['total_messages'] / $runtime) * 60, 2) : 0,
            'error_rate' => self::$stats['total_messages'] > 0 
                ? round((self::$stats['error_messages'] / self::$stats['total_messages']) * 100, 2) 
                : 0,
            'invalid_rate' => self::$stats['total_messages'] > 0 
                ? round((self::$stats['invalid_messages'] / self::$stats['total_messages']) * 100, 2) 
                : 0,
            'update_time' => time()
        ]);
    }

    /**
     * 重置统计信息
     */
    public static function resetStats()
    {
        self::$stats = [
            'total_messages' => 0,
            'auth_messages' => 0,
            'game_messages' => 0,
            'user_messages' => 0,
            'ping_messages' => 0,
            'error_messages' => 0,
            'invalid_messages' => 0,
            'start_time' => time()
        ];
        
        echo "[MessageHandler] 消息统计已重置\n";
    }

    /**
     * 获取支持的消息类型列表
     * @return array
     */
    public static function getSupportedMessageTypes()
    {
        return [
            self::MSG_PING => '心跳检测',
            self::MSG_PONG => '心跳响应',
            self::MSG_AUTH => '用户认证',
            self::MSG_LOGOUT => '用户登出',
            self::MSG_JOIN_TABLE => '加入台桌',
            self::MSG_LEAVE_TABLE => '离开台桌',
            self::MSG_GAME_STATUS => '游戏状态查询',
            self::MSG_GET_BALANCE => '获取余额',
            self::MSG_GET_BET_HISTORY => '获取投注历史'
        ];
    }

    /**
     * 处理批量消息
     * @param TcpConnection $connection
     * @param array $messages
     * @return array 处理结果
     */
    public static function handleBatch(TcpConnection $connection, array $messages)
    {
        $results = [];
        $maxBatchSize = 10; // 最大批量处理数量
        
        if (count($messages) > $maxBatchSize) {
            self::sendError($connection, "批量消息数量不能超过{$maxBatchSize}个", 'BATCH_TOO_LARGE');
            return [];
        }

        foreach ($messages as $index => $messageData) {
            try {
                if (is_string($messageData)) {
                    self::handle($connection, $messageData);
                    $results[$index] = ['success' => true];
                } else {
                    $results[$index] = ['success' => false, 'error' => '消息格式错误'];
                }
            } catch (\Exception $e) {
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * 设置消息速率限制
     * @param int $limit 每分钟最大消息数
     */
    public static function setRateLimit($limit)
    {
        if ($limit > 0 && $limit <= 1000) {
            self::MESSAGE_RATE_LIMIT = $limit;
            echo "[MessageHandler] 消息速率限制已设置为: {$limit}/分钟\n";
        }
    }

    /**
     * 获取连接详细信息
     * @param TcpConnection $connection
     * @return array
     */
    public static function getConnectionInfo(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        if (!$connectionData) {
            return [];
        }

        return [
            'connection_id' => $connectionId,
            'user_id' => $connectionData['user_id'],
            'table_id' => $connectionData['table_id'],
            'auth_status' => $connectionData['auth_status'],
            'connect_time' => $connectionData['connect_time'],
            'last_ping' => $connectionData['last_ping'],
            'last_activity' => $connectionData['last_activity'],
            'remote_ip' => $connectionData['remote_ip'],
            'message_count' => $connectionData['message_count'],
            'duration' => time() - $connectionData['connect_time']
        ];
    }
}