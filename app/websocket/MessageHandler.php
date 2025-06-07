<?php

namespace app\websocket;

use Workerman\Connection\TcpConnection;
use app\websocket\events\GameEventHandler;
use think\facade\Log;
use think\facade\Db;

/**
 * WebSocket 消息处理器 - 简化版
 * 只保留10个核心方法，专注于消息解析和路由
 * 集成简单认证逻辑，删除批量处理等复杂功能
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

    /**
     * 最大消息长度（字节）
     */
    const MAX_MESSAGE_LENGTH = 8192; // 8KB

    /**
     * 消息频率限制（每分钟）
     */
    const MESSAGE_RATE_LIMIT = 60;

    /**
     * 消息频率计数器
     */
    private static $messageCounters = [];

    /**
     * 1. 处理WebSocket消息（主入口）
     * @param TcpConnection $connection
     * @param string $data
     */
    public static function handle(TcpConnection $connection, $data)
    {
        $connectionId = spl_object_hash($connection);
        
        try {
            // 检查消息长度
            if (strlen($data) > self::MAX_MESSAGE_LENGTH) {
                self::sendError($connection, '消息长度超过限制', 'MESSAGE_TOO_LARGE');
                return;
            }

            // 检查消息频率
            if (!self::checkMessageRate($connectionId)) {
                self::sendError($connection, '消息发送过于频繁', 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // 解析JSON消息
            $message = json_decode($data, true);
            if (!$message || !is_array($message)) {
                self::sendError($connection, '消息格式错误', 'INVALID_JSON');
                return;
            }

            // 检查消息类型
            if (!isset($message['type']) || empty($message['type'])) {
                self::sendError($connection, '缺少消息类型', 'MISSING_TYPE');
                return;
            }

            $messageType = $message['type'];
            
            // 更新连接活动时间
            ConnectionManager::updatePing($connectionId);

            // 验证基础消息格式
            if (!self::validateMessage($message)) {
                self::sendError($connection, '消息格式验证失败', 'INVALID_FORMAT');
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
                    self::handleAuth($connection, $message);
                    break;

                case self::MSG_LOGOUT:
                    self::handleLogout($connection, $message);
                    break;

                case self::MSG_JOIN_TABLE:
                case self::MSG_LEAVE_TABLE:
                case self::MSG_GAME_STATUS:
                    // 这些消息需要先认证
                    if (!self::isAuthenticated($connection)) {
                        self::sendError($connection, '请先进行身份认证', 'AUTH_REQUIRED');
                        return;
                    }
                    GameEventHandler::handle($connection, $message);
                    break;

                default:
                    self::sendError($connection, '未知的消息类型: ' . $messageType, 'UNKNOWN_TYPE');
                    break;
            }

        } catch (\Exception $e) {
            Log::error('消息处理异常', [
                'connection_id' => $connectionId,
                'data' => substr($data, 0, 500),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            self::sendError($connection, '消息处理失败', 'SERVER_ERROR');
        }
    }

    /**
     * 2. 处理心跳消息
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handlePing(TcpConnection $connection, array $message)
    {
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
     * 3. 处理心跳响应消息
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
                // 延迟正常，可以记录到连接信息中（可选）
            }
        }
    }

    /**
     * 4. 处理用户认证（集成简单认证逻辑）
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleAuth(TcpConnection $connection, array $message)
    {
        $connectionId = spl_object_hash($connection);
        
        try {
            // 验证必需参数
            if (!isset($message['user_id']) || !isset($message['token'])) {
                self::sendError($connection, '认证参数不完整，需要user_id和token', 'AUTH_INVALID_PARAMS');
                return;
            }

            $userId = (int)$message['user_id'];
            $token = $message['token'];

            // 基础参数验证
            if ($userId <= 0 || empty($token)) {
                self::sendError($connection, '用户ID或token无效', 'AUTH_INVALID_PARAMS');
                return;
            }

            // 简单token验证
            if (!self::validateUserToken($userId, $token)) {
                self::sendError($connection, '认证失败，用户ID与token不匹配', 'AUTH_FAILED');
                
                Log::warning('用户认证失败', [
                    'user_id' => $userId,
                    'token' => substr($token, 0, 10) . '...',
                    'remote_ip' => $connection->getRemoteIp(),
                    'connection_id' => $connectionId
                ]);
                
                return;
            }

            // 检查用户状态
            if (!self::isUserActive($userId)) {
                self::sendError($connection, '用户账户已被禁用', 'AUTH_USER_INACTIVE');
                
                Log::warning('禁用用户尝试连接', [
                    'user_id' => $userId,
                    'remote_ip' => $connection->getRemoteIp()
                ]);
                
                return;
            }

            // 更新连接管理器中的用户信息
            $success = ConnectionManager::authenticateUser($connectionId, $userId);
            
            if (!$success) {
                self::sendError($connection, '认证处理失败，请重试', 'AUTH_PROCESS_FAILED');
                return;
            }

            // 发送认证成功响应
            self::sendSuccess($connection, 'auth_success', [
                'user_id' => $userId,
                'auth_time' => time()
            ], '认证成功');

            // 记录认证成功日志
            Log::info('用户WebSocket认证成功', [
                'user_id' => $userId,
                'connection_id' => $connectionId,
                'remote_ip' => $connection->getRemoteIp()
            ]);

            echo "[" . date('Y-m-d H:i:s') . "] 用户认证成功: UserID {$userId}, Connection {$connectionId}\n";

        } catch (\Exception $e) {
            Log::error('认证处理异常', [
                'user_id' => $userId ?? 0,
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            self::sendError($connection, '认证处理异常，请重试', 'AUTH_SERVER_ERROR');
        }
    }

    /**
     * 5. 处理用户登出
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleLogout(TcpConnection $connection, array $message)
    {
        $connectionId = spl_object_hash($connection);
        
        try {
            // 获取连接信息
            $connectionData = ConnectionManager::getConnection($connectionId);
            
            if (!$connectionData) {
                self::sendError($connection, '连接信息不存在', 'LOGOUT_CONNECTION_NOT_FOUND');
                return;
            }

            if (!$connectionData['auth_status']) {
                self::sendError($connection, '用户未登录', 'LOGOUT_NOT_AUTHENTICATED');
                return;
            }

            $userId = $connectionData['user_id'];
            $tableId = $connectionData['table_id'];

            // 如果在台桌中，先离开台桌
            if ($tableId) {
                ConnectionManager::leaveTable($connectionId, $tableId);
                echo "[" . date('Y-m-d H:i:s') . "] 用户自动离开台桌: UserID {$userId}, Table {$tableId}\n";
            }

            // 清除认证状态（保持连接，只清除认证）
            ConnectionManager::clearAuthentication($connectionId);

            // 发送登出成功响应
            self::sendSuccess($connection, 'logout_success', [
                'user_id' => $userId,
                'logout_time' => time()
            ], '登出成功');

            // 记录登出日志
            Log::info('用户WebSocket登出', [
                'user_id' => $userId,
                'connection_id' => $connectionId,
                'was_in_table' => $tableId ? true : false,
                'table_id' => $tableId
            ]);

            echo "[" . date('Y-m-d H:i:s') . "] 用户登出: UserID {$userId}, Connection {$connectionId}\n";

        } catch (\Exception $e) {
            Log::error('登出处理异常', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            self::sendError($connection, '登出处理失败', 'LOGOUT_SERVER_ERROR');
        }
    }

    /**
     * 6. 发送成功响应
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
     * 7. 发送错误响应
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
     * 8. 发送消息到连接
     * @param TcpConnection $connection
     * @param array $data
     * @return bool
     */
    public static function sendToConnection(TcpConnection $connection, array $data)
    {
        return ConnectionManager::sendToConnection($connection, $data);
    }

    /**
     * 9. 验证连接是否已认证
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
     * 10. 验证消息格式
     * @param array $message
     * @return bool
     */
    public static function validateMessage(array $message)
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

    // ========================================
    // 辅助方法（内部使用）
    // ========================================

    /**
     * 检查消息频率限制
     * @param string $connectionId
     * @return bool
     */
    private static function checkMessageRate($connectionId)
    {
        $currentTime = time();
        $timeWindow = 60; // 60秒窗口
        
        // 每5分钟清理一次过期计数器
        static $lastCleanup = 0;
        if ($currentTime - $lastCleanup > 300) {
            self::$messageCounters = [];
            $lastCleanup = $currentTime;
        }

        // 初始化计数器
        if (!isset(self::$messageCounters[$connectionId])) {
            self::$messageCounters[$connectionId] = [
                'count' => 0,
                'window_start' => $currentTime
            ];
        }

        $counter = &self::$messageCounters[$connectionId];

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
     * 简单token验证（集成的认证逻辑）
     * @param int $userId
     * @param string $token
     * @return bool
     */
    private static function validateUserToken($userId, $token)
    {
        // 简单的token生成策略：user_id + 固定密钥的MD5
        $secret = 'sicbo_websocket_secret_2024'; // 可配置
        $expectedToken = md5($userId . '_' . $secret . '_websocket');
        
        return $token === $expectedToken;
    }

    /**
     * 检查用户是否活跃可用
     * @param int $userId
     * @return bool
     */
    private static function isUserActive($userId)
    {
        try {
            // 尝试常见的用户表名
            $tableNames = ['common_user', 'users', 'user', 'dianji_user'];
            
            foreach ($tableNames as $tableName) {
                try {
                    $user = Db::name($tableName)
                        ->where('id', $userId)
                        ->find();
                        
                    if ($user) {
                        return (int)($user['status'] ?? 0) === 1; // 1=活跃
                    }
                } catch (\Exception $e) {
                    // 表不存在，尝试下一个
                    continue;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('检查用户状态失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 获取连接的用户ID
     * @param TcpConnection $connection
     * @return int|null
     */
    public static function getConnectionUserId(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        return $connectionData ? $connectionData['user_id'] : null;
    }

    /**
     * 获取连接的台桌ID
     * @param TcpConnection $connection
     * @return int|null
     */
    public static function getConnectionTableId(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        return $connectionData ? $connectionData['table_id'] : null;
    }
}