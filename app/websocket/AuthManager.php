<?php

namespace app\websocket;

use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * WebSocket 认证管理器
 * 负责处理用户认证、权限验证、Token管理等
 */
class AuthManager
{
    /**
     * Token 缓存前缀
     */
    private const TOKEN_CACHE_PREFIX = 'ws_token_';
    
    /**
     * 用户信息缓存前缀
     */
    private const USER_CACHE_PREFIX = 'ws_user_';
    
    /**
     * Token 默认有效期（秒）
     */
    private const TOKEN_EXPIRE = 7200; // 2小时

    /**
     * 验证用户Token
     * @param string $token
     * @param int|null $userId 可选的用户ID验证
     * @return array|null 返回用户信息或null
     */
    public static function validateToken($token, $userId = null)
    {
        if (empty($token)) {
            return null;
        }

        try {
            // 首先从缓存获取
            $cacheKey = self::TOKEN_CACHE_PREFIX . md5($token);
            $userInfo = Cache::get($cacheKey);
            
            if (!$userInfo) {
                // 缓存中没有，从数据库验证
                $userInfo = self::validateTokenFromDatabase($token, $userId);
                
                if ($userInfo) {
                    // 缓存用户信息
                    Cache::set($cacheKey, $userInfo, self::TOKEN_EXPIRE);
                }
            }
            
            // 验证用户状态
            if ($userInfo && !self::isUserActive($userInfo['user_id'])) {
                // 用户被禁用，清除缓存
                Cache::delete($cacheKey);
                return null;
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
     * 从数据库验证Token
     * @param string $token
     * @param int|null $userId
     * @return array|null
     */
    private static function validateTokenFromDatabase($token, $userId = null)
    {
        try {
            // 这里实现具体的Token验证逻辑
            // 方案1：如果有专门的token表
            $tokenRecord = Db::table('user_tokens')
                ->where('token', $token)
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->where('status', 1)
                ->find();
                
            if ($tokenRecord) {
                $userId = $tokenRecord['user_id'];
            } else {
                // 方案2：简化验证 - 实际项目中应该使用JWT或其他标准方案
                if (strlen($token) >= 6) {
                    // 这里可以解析token获取用户ID，或使用其他验证方式
                    // 示例：如果token格式是 "user_{user_id}_{timestamp}_{hash}"
                    if (preg_match('/^user_(\d+)_/', $token, $matches)) {
                        $userId = (int)$matches[1];
                    } elseif ($userId > 0) {
                        // 使用传入的用户ID
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
            }

            // 获取用户信息
            if ($userId > 0) {
                $user = Db::table('users')
                    ->where('id', $userId)
                    ->where('status', 1)
                    ->find();
                    
                if ($user) {
                    return [
                        'user_id' => $user['id'],
                        'username' => $user['username'],
                        'nickname' => $user['nickname'] ?? '',
                        'avatar' => $user['avatar'] ?? '',
                        'level' => $user['level'] ?? 1,
                        'status' => $user['status'],
                        'last_login' => $user['last_login_time'] ?? null
                    ];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('数据库Token验证失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 检查用户是否活跃可用
     * @param int $userId
     * @return bool
     */
    public static function isUserActive($userId)
    {
        try {
            $cacheKey = self::USER_CACHE_PREFIX . "status_{$userId}";
            $status = Cache::get($cacheKey);
            
            if ($status === null) {
                $status = Db::table('users')
                    ->where('id', $userId)
                    ->value('status');
                    
                // 缓存10分钟
                Cache::set($cacheKey, $status ?? 0, 600);
            }
            
            return $status == 1;
            
        } catch (\Exception $e) {
            Log::error('检查用户状态失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查用户权限
     * @param int $userId
     * @param string $permission 权限名称
     * @param int|null $tableId 台桌ID（某些权限需要）
     * @return bool
     */
    public static function checkPermission($userId, $permission, $tableId = null)
    {
        try {
            $cacheKey = self::USER_CACHE_PREFIX . "perm_{$userId}_{$permission}";
            if ($tableId) {
                $cacheKey .= "_{$tableId}";
            }
            
            $hasPermission = Cache::get($cacheKey);
            
            if ($hasPermission === null) {
                $hasPermission = self::validatePermission($userId, $permission, $tableId);
                
                // 缓存权限结果5分钟
                Cache::set($cacheKey, $hasPermission, 300);
            }
            
            return (bool)$hasPermission;
            
        } catch (\Exception $e) {
            Log::error('权限检查失败: ' . $e->getMessage(), [
                'user_id' => $userId,
                'permission' => $permission,
                'table_id' => $tableId
            ]);
            return false;
        }
    }

    /**
     * 验证具体权限
     * @param int $userId
     * @param string $permission
     * @param int|null $tableId
     * @return bool
     */
    private static function validatePermission($userId, $permission, $tableId = null)
    {
        try {
            // 获取用户基本信息
            $user = Db::table('users')->where('id', $userId)->find();
            if (!$user || $user['status'] != 1) {
                return false;
            }

            $userLevel = (int)$user['level'];

            // 根据权限类型检查
            switch ($permission) {
                case 'bet':
                    // 投注权限：所有正常用户都有
                    return $userLevel >= 1;
                    
                case 'join_table':
                    // 加入台桌权限
                    if ($tableId) {
                        // 检查台桌是否允许该用户加入
                        $table = Db::table('dianji_table')
                            ->where('id', $tableId)
                            ->where('status', 1)
                            ->find();
                        return $table !== null;
                    }
                    return $userLevel >= 1;
                    
                case 'admin':
                    // 管理员权限
                    return $userLevel >= 9;
                    
                case 'moderator':
                    // 版主权限
                    return $userLevel >= 5;
                    
                case 'vip':
                    // VIP权限
                    return $userLevel >= 3;
                    
                case 'chat':
                    // 聊天权限
                    return $userLevel >= 1 && !self::isUserMuted($userId);
                    
                default:
                    // 未知权限默认拒绝
                    return false;
            }
            
        } catch (\Exception $e) {
            Log::error('权限验证失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查用户是否被禁言
     * @param int $userId
     * @return bool
     */
    private static function isUserMuted($userId)
    {
        try {
            $muteRecord = Db::table('user_mutes')
                ->where('user_id', $userId)
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->where('status', 1)
                ->find();
                
            return $muteRecord !== null;
            
        } catch (\Exception $e) {
            Log::error('检查禁言状态失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 生成新的Token
     * @param int $userId
     * @param array $options 选项
     * @return string|null
     */
    public static function generateToken($userId, array $options = [])
    {
        try {
            $expireTime = $options['expire'] ?? self::TOKEN_EXPIRE;
            $deviceId = $options['device_id'] ?? '';
            
            // 生成Token
            $token = 'user_' . $userId . '_' . time() . '_' . uniqid() . '_' . md5($deviceId . $userId);
            
            // 保存到数据库（如果有token表）
            $tokenData = [
                'user_id' => $userId,
                'token' => $token,
                'device_id' => $deviceId,
                'expires_at' => date('Y-m-d H:i:s', time() + $expireTime),
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 1
            ];
            
            // 检查是否有user_tokens表
            $tableExists = Db::query("SHOW TABLES LIKE 'user_tokens'");
            if ($tableExists) {
                Db::table('user_tokens')->insert($tokenData);
            }
            
            // 缓存Token信息
            $userInfo = self::getUserInfo($userId);
            if ($userInfo) {
                $cacheKey = self::TOKEN_CACHE_PREFIX . md5($token);
                Cache::set($cacheKey, $userInfo, $expireTime);
            }
            
            return $token;
            
        } catch (\Exception $e) {
            Log::error('生成Token失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取用户信息
     * @param int $userId
     * @return array|null
     */
    public static function getUserInfo($userId)
    {
        try {
            $cacheKey = self::USER_CACHE_PREFIX . "info_{$userId}";
            $userInfo = Cache::get($cacheKey);
            
            if (!$userInfo) {
                $user = Db::table('users')
                    ->where('id', $userId)
                    ->field('id,username,nickname,avatar,level,status,created_at,last_login_time')
                    ->find();
                    
                if ($user) {
                    $userInfo = [
                        'user_id' => $user['id'],
                        'username' => $user['username'],
                        'nickname' => $user['nickname'] ?? '',
                        'avatar' => $user['avatar'] ?? '',
                        'level' => $user['level'] ?? 1,
                        'status' => $user['status'],
                        'created_at' => $user['created_at'],
                        'last_login' => $user['last_login_time'] ?? null
                    ];
                    
                    // 缓存30分钟
                    Cache::set($cacheKey, $userInfo, 1800);
                }
            }
            
            return $userInfo;
            
        } catch (\Exception $e) {
            Log::error('获取用户信息失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取用户余额
     * @param int $userId
     * @return float
     */
    public static function getUserBalance($userId)
    {
        try {
            $cacheKey = self::USER_CACHE_PREFIX . "balance_{$userId}";
            $balance = Cache::get($cacheKey);
            
            if ($balance === null) {
                $userBalance = Db::table('user_balance')
                    ->where('user_id', $userId)
                    ->value('balance');
                    
                $balance = $userBalance ?? 0.00;
                
                // 缓存5分钟
                Cache::set($cacheKey, $balance, 300);
            }
            
            return (float)$balance;
            
        } catch (\Exception $e) {
            Log::error('获取用户余额失败: ' . $e->getMessage());
            return 0.00;
        }
    }

    /**
     * 撤销Token
     * @param string $token
     * @return bool
     */
    public static function revokeToken($token)
    {
        try {
            // 从缓存中删除
            $cacheKey = self::TOKEN_CACHE_PREFIX . md5($token);
            Cache::delete($cacheKey);
            
            // 从数据库中标记为无效（如果有token表）
            $tableExists = Db::query("SHOW TABLES LIKE 'user_tokens'");
            if ($tableExists) {
                Db::table('user_tokens')
                    ->where('token', $token)
                    ->update([
                        'status' => 0,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('撤销Token失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 撤销用户的所有Token
     * @param int $userId
     * @return bool
     */
    public static function revokeUserTokens($userId)
    {
        try {
            // 从数据库中撤销所有token
            $tableExists = Db::query("SHOW TABLES LIKE 'user_tokens'");
            if ($tableExists) {
                Db::table('user_tokens')
                    ->where('user_id', $userId)
                    ->update([
                        'status' => 0,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }
            
            // 清除用户相关缓存
            self::clearUserCache($userId);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('撤销用户Token失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 清除用户相关缓存
     * @param int $userId
     */
    public static function clearUserCache($userId)
    {
        try {
            $patterns = [
                self::USER_CACHE_PREFIX . "info_{$userId}",
                self::USER_CACHE_PREFIX . "balance_{$userId}",
                self::USER_CACHE_PREFIX . "status_{$userId}",
            ];

            foreach ($patterns as $key) {
                Cache::delete($key);
            }
            
            // 清除权限相关缓存（这里简化处理，实际可能需要更精确的清理）
            $permissions = ['bet', 'join_table', 'admin', 'moderator', 'vip', 'chat'];
            foreach ($permissions as $perm) {
                Cache::delete(self::USER_CACHE_PREFIX . "perm_{$userId}_{$perm}");
            }
            
        } catch (\Exception $e) {
            Log::error('清除用户缓存失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量验证Token
     * @param array $tokens
     * @return array 返回有效的token对应的用户信息
     */
    public static function batchValidateTokens(array $tokens)
    {
        $result = [];
        
        foreach ($tokens as $token) {
            $userInfo = self::validateToken($token);
            if ($userInfo) {
                $result[$token] = $userInfo;
            }
        }
        
        return $result;
    }

    /**
     * 更新用户最后登录时间
     * @param int $userId
     * @return bool
     */
    public static function updateLastLogin($userId)
    {
        try {
            Db::table('users')
                ->where('id', $userId)
                ->update([
                    'last_login_time' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
            // 清除用户信息缓存，下次获取时会重新缓存
            Cache::delete(self::USER_CACHE_PREFIX . "info_{$userId}");
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('更新最后登录时间失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取Token信息
     * @param string $token
     * @return array|null
     */
    public static function getTokenInfo($token)
    {
        try {
            $tableExists = Db::query("SHOW TABLES LIKE 'user_tokens'");
            if (!$tableExists) {
                return null;
            }
            
            $tokenRecord = Db::table('user_tokens')
                ->where('token', $token)
                ->find();
                
            return $tokenRecord;
            
        } catch (\Exception $e) {
            Log::error('获取Token信息失败: ' . $e->getMessage());
            return null;
        }
    }
}