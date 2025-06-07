<?php

namespace app\websocket\events;

use Workerman\Connection\TcpConnection;
use app\websocket\ConnectionManager;
use app\websocket\AuthManager;
use think\facade\Log;

/**
 * 认证事件处理器 - 简化版
 * 只处理用户认证相关的WebSocket消息
 * 适配 PHP 7.3 + ThinkPHP6
 */
class AuthEventHandler
{
    /**
     * 处理认证相关消息
     * @param TcpConnection $connection
     * @param array $message
     */
    public static function handle(TcpConnection $connection, array $message)
    {
        $messageType = $message['type'] ?? '';
        
        try {
            switch ($messageType) {
                case 'auth':
                    self::handleAuth($connection, $message);
                    break;
                    
                case 'logout':
                    self::handleLogout($connection, $message);
                    break;
                    
                default:
                    self::sendError($connection, '未知的认证消息类型: ' . $messageType);
                    break;
            }
            
        } catch (\Exception $e) {
            Log::error('认证事件处理异常', [
                'message_type' => $messageType,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            self::sendError($connection, '认证处理失败');
        }
    }

    /**
     * 处理用户认证
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleAuth(TcpConnection $connection, array $message)
    {
        $connectionId = spl_object_hash($connection);
        $remoteIp = $connection->getRemoteIp();
        
        try {
            // 验证必需参数
            if (!self::validateAuthMessage($message)) {
                self::sendError($connection, '认证参数不完整，需要user_id和token');
                return;
            }

            $userId = (int)$message['user_id'];
            $token = $message['token'];

            // 基础参数验证
            if ($userId <= 0 || empty($token)) {
                self::sendError($connection, '用户ID或token无效');
                return;
            }

            // 验证用户token
            $userInfo = AuthManager::validateUserToken($userId, $token);
            
            if (!$userInfo) {
                self::sendError($connection, '认证失败，用户ID与token不匹配');
                
                Log::warning('用户认证失败', [
                    'user_id' => $userId,
                    'token' => substr($token, 0, 10) . '...',
                    'remote_ip' => $remoteIp,
                    'connection_id' => $connectionId
                ]);
                
                return;
            }

            // 检查用户状态
            if (!AuthManager::isUserActive($userId)) {
                self::sendError($connection, '用户账户已被禁用');
                
                Log::warning('禁用用户尝试连接', [
                    'user_id' => $userId,
                    'remote_ip' => $remoteIp
                ]);
                
                return;
            }

            // 更新连接管理器中的用户信息
            $success = ConnectionManager::authenticateUser($connectionId, $userId);
            
            if (!$success) {
                self::sendError($connection, '认证处理失败，请重试');
                return;
            }

            // 发送认证成功响应
            self::sendSuccess($connection, 'auth_success', [
                'user_id' => $userId,
                'username' => $userInfo['username'] ?? '',
                'nickname' => $userInfo['nickname'] ?? '',
                'level' => $userInfo['level'] ?? 1,
                'auth_time' => time()
            ], '认证成功');

            // 记录认证成功日志
            Log::info('用户WebSocket认证成功', [
                'user_id' => $userId,
                'username' => $userInfo['username'] ?? '',
                'connection_id' => $connectionId,
                'remote_ip' => $remoteIp,
                'user_agent' => $connection->headers['user-agent'] ?? ''
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
            
            self::sendError($connection, '认证处理异常，请重试');
        }
    }

    /**
     * 处理用户登出
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
                self::sendError($connection, '连接信息不存在');
                return;
            }

            if (!$connectionData['auth_status']) {
                self::sendError($connection, '用户未登录');
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
            
            self::sendError($connection, '登出处理失败');
        }
    }

    /**
     * 验证认证消息格式
     * @param array $message
     * @return bool
     */
    private static function validateAuthMessage(array $message)
    {
        // 检查必需字段
        $requiredFields = ['user_id', 'token'];
        
        foreach ($requiredFields as $field) {
            if (!isset($message[$field]) || empty($message[$field])) {
                return false;
            }
        }

        // 检查数据类型
        if (!is_numeric($message['user_id'])) {
            return false;
        }

        if (!is_string($message['token'])) {
            return false;
        }

        // 检查token长度（基础验证）
        if (strlen($message['token']) < 6) {
            return false;
        }

        return true;
    }

    /**
     * 发送成功响应
     * @param TcpConnection $connection
     * @param string $type
     * @param array $data
     * @param string $message
     */
    private static function sendSuccess(TcpConnection $connection, $type, array $data = [], $message = 'success')
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
    private static function sendError(TcpConnection $connection, $message, $type = 'auth_failed')
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
     * 发送消息到连接
     * @param TcpConnection $connection
     * @param array $data
     */
    private static function sendToConnection(TcpConnection $connection, array $data)
    {
        try {
            $message = json_encode($data, JSON_UNESCAPED_UNICODE);
            $connection->send($message);
        } catch (\Exception $e) {
            echo "[ERROR] 发送认证消息失败: " . $e->getMessage() . "\n";
            Log::error('发送认证消息失败', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
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
     * 检查连接是否已认证
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
     * 强制用户下线
     * @param int $userId
     * @param string $reason
     * @return int 强制下线的连接数
     */
    public static function forceUserLogout($userId, $reason = '管理员操作')
    {
        try {
            $message = [
                'type' => 'force_logout',
                'success' => false,
                'message' => $reason,
                'timestamp' => time()
            ];

            // 发送强制下线消息并断开连接
            $sentCount = ConnectionManager::sendToUser($userId, $message);
            
            // 记录强制下线日志
            Log::warning('强制用户下线', [
                'user_id' => $userId,
                'reason' => $reason,
                'connections_affected' => $sentCount
            ]);

            return $sentCount;

        } catch (\Exception $e) {
            Log::error('强制用户下线失败', [
                'user_id' => $userId,
                'reason' => $reason,
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }

    /**
     * 批量强制用户下线
     * @param array $userIds
     * @param string $reason
     * @return array
     */
    public static function batchForceLogout(array $userIds, $reason = '批量管理操作')
    {
        $results = [];
        
        foreach ($userIds as $userId) {
            $results[$userId] = self::forceUserLogout($userId, $reason);
        }
        
        return $results;
    }

    /**
     * 获取认证统计信息
     * @return array
     */
    public static function getAuthStats()
    {
        try {
            $stats = ConnectionManager::getOnlineStats();
            
            return [
                'total_connections' => $stats['total_connections'] ?? 0,
                'authenticated_users' => $stats['authenticated_users'] ?? 0,
                'unauthenticated_connections' => ($stats['total_connections'] ?? 0) - ($stats['authenticated_users'] ?? 0),
                'authentication_rate' => $stats['total_connections'] > 0 
                    ? round(($stats['authenticated_users'] / $stats['total_connections']) * 100, 2) 
                    : 0,
                'timestamp' => time()
            ];
            
        } catch (\Exception $e) {
            Log::error('获取认证统计失败', ['error' => $e->getMessage()]);
            
            return [
                'total_connections' => 0,
                'authenticated_users' => 0,
                'unauthenticated_connections' => 0,
                'authentication_rate' => 0,
                'timestamp' => time(),
                'error' => $e->getMessage()
            ];
        }
    }
}