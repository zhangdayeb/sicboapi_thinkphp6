<?php

namespace app\websocket\events;

use Workerman\Connection\TcpConnection;
use app\websocket\ConnectionManager;
use app\websocket\MessageHandler;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * 认证事件处理器
 * 处理用户认证相关的WebSocket消息
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
        $messageType = $message['type'];
        
        switch ($messageType) {
            case 'auth':
                self::handleAuth($connection, $message);
                break;
                
            case 'logout':
                self::handleLogout($connection, $message);
                break;
                
            default:
                MessageHandler::sendError($connection, '未知的认证消息类型');
                break;
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
        
        try {
            // 验证必需参数
            if (!MessageHandler::validateMessage($message, ['token'])) {
                MessageHandler::sendError($connection, '认证参数不完整，需要token');
                return;
            }

            $token = $message['token'];
            $userId = $message['user_id'] ?? null;

            // 验证token
            $userInfo = self::validateToken($token, $userId);
            
            if (!$userInfo) {
                MessageHandler::sendError($connection, '认证失败，无效的token');
                return;
            }

            $userId = $userInfo['user_id'];

            // 更新连接管理器中的用户信息
            $success = ConnectionManager::authenticateUser($connectionId, $userId);
            
            if (!$success) {
                MessageHandler::sendError($connection, '认证处理失败');
                return;
            }

            // 发送认证成功响应
            MessageHandler::sendSuccess($connection, 'auth_success', [
                'user_id' => $userId,
                'user_info' => [
                    'username' => $userInfo['username'],
                    'nickname' => $userInfo['nickname'] ?? '',
                    'avatar' => $userInfo['avatar'] ?? '',
                    'level' => $userInfo['level'] ?? 1,
                    'balance' => self::getUserBalance($userId)
                ]
            ], '认证成功');

            // 记录认证日志
            Log::info('用户WebSocket认证成功', [
                'user_id' => $userId,
                'connection_id' => $connectionId,
                'remote_ip' => $connection->getRemoteIp()
            ]);

        } catch (\Exception $e) {
            Log::error('认证处理异常: ' . $e->getMessage(), [
                'connection_id' => $connectionId,
                'token' => substr($token ?? '', 0, 10) . '...'
            ]);
            
            MessageHandler::sendError($connection, '认证处理失败');
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
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        if (!$connectionData || !$connectionData['auth_status']) {
            MessageHandler::sendError($connection, '用户未登录');
            return;
        }

        $userId = $connectionData['user_id'];
        
        // 移除连接（这会自动处理台桌离开等清理工作）
        ConnectionManager::removeConnection($connection);
        
        // 发送登出成功响应
        MessageHandler::sendSuccess($connection, 'logout_success', [], '登出成功');
        
        // 记录登出日志
        Log::info('用户WebSocket登出', [
            'user_id' => $userId,
            'connection_id' => $connectionId
        ]);
    }

    /**
     * 验证用户token
     * @param string $token
     * @param int|null $userId
     * @return array|null
     */
    private static function validateToken($token, $userId = null)
    {
        try {
            // 首先从缓存获取
            $cacheKey = 'user_token_' . md5($token);
            $userInfo = Cache::get($cacheKey);
            
            if (!$userInfo) {
                // 从数据库查询
                $query = Db::table('users')
                    ->where('status', 1);
                    
                // 如果提供了用户ID，同时验证
                if ($userId) {
                    $query->where('id', $userId);
                }
                
                // 这里简化token验证，实际项目中应该有专门的token字段
                // 或者使用JWT等标准token机制
                if (strlen($token) >= 6) {
                    // 模拟token验证逻辑
                    if ($userId) {
                        $user = $query->where('id', $userId)->find();
                    } else {
                        // 如果没有提供用户ID，可以通过其他方式验证token
                        // 这里简化处理，假设token中包含用户ID信息
                        $user = $query->where('id', 1)->find(); // 示例：默认用户ID为1
                    }
                } else {
                    return null;
                }
                    
                if ($user) {
                    $userInfo = [
                        'user_id' => $user['id'],
                        'username' => $user['username'],
                        'nickname' => $user['nickname'] ?? '',
                        'avatar' => $user['avatar'] ?? '',
                        'level' => $user['level'] ?? 1,
                        'status' => $user['status']
                    ];
                    
                    // 缓存用户信息
                    Cache::set($cacheKey, $userInfo, 7200); // 2小时缓存
                }
            }
            
            return $userInfo;
            
        } catch (\Exception $e) {
            Log::error('Token验证异常: ' . $e->getMessage(), [
                'token' => substr($token, 0, 10) . '...',
                'user_id' => $userId
            ]);
            return null;
        }
    }

    /**
     * 获取用户余额
     * @param int $userId
     * @return float
     */
    private static function getUserBalance($userId)
    {
        try {
            // 从缓存获取
            $cacheKey = "user_balance_{$userId}";
            $balance = Cache::get($cacheKey);
            
            if ($balance === null) {
                // 从数据库获取
                $userBalance = Db::table('user_balance')
                    ->where('user_id', $userId)
                    ->value('balance');
                    
                $balance = $userBalance ?? 0.00;
                
                // 缓存5分钟
                Cache::set($cacheKey, $balance, 300);
            }
            
            return (float)$balance;
            
        } catch (\Exception $e) {
            Log::error('获取用户余额失败: ' . $e->getMessage(), [
                'user_id' => $userId
            ]);
            return 0.00;
        }
    }

    /**
     * 检查用户权限
     * @param int $userId
     * @param string $permission
     * @return bool
     */
    public static function checkUserPermission($userId, $permission)
    {
        try {
            // 这里可以实现具体的权限检查逻辑
            // 简化处理，根据用户等级判断
            $cacheKey = "user_level_{$userId}";
            $userLevel = Cache::get($cacheKey);
            
            if ($userLevel === null) {
                $userLevel = Db::table('users')
                    ->where('id', $userId)
                    ->value('level') ?? 1;
                    
                Cache::set($cacheKey, $userLevel, 1800); // 30分钟缓存
            }
            
            // 权限检查逻辑
            switch ($permission) {
                case 'bet':
                    return $userLevel >= 1; // 所有用户都可以投注
                case 'admin':
                    return $userLevel >= 9; // 管理员权限
                default:
                    return true;
            }
            
        } catch (\Exception $e) {
            Log::error('权限检查失败: ' . $e->getMessage(), [
                'user_id' => $userId,
                'permission' => $permission
            ]);
            return false;
        }
    }

    /**
     * 刷新用户token
     * @param TcpConnection $connection
     * @param array $message
     */
    public static function refreshToken(TcpConnection $connection, array $message)
    {
        // 实现token刷新逻辑
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        if (!$connectionData || !$connectionData['auth_status']) {
            MessageHandler::sendError($connection, '用户未登录');
            return;
        }

        $userId = $connectionData['user_id'];
        
        // 生成新token（实际项目中应该有专门的token生成逻辑）
        $newToken = 'token_' . $userId . '_' . time() . '_' . uniqid();
        
        MessageHandler::sendSuccess($connection, 'token_refreshed', [
            'new_token' => $newToken,
            'expires_in' => 7200 // 2小时
        ], 'Token已刷新');
    }
}