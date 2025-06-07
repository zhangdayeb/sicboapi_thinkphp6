<?php

namespace app\websocket;

use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * WebSocket 认证管理器 - 完整版
 * 负责处理用户认证、权限验证、Token管理等
 * 基于user_id + token的简单认证方案
 * 适配 PHP 7.3 + ThinkPHP6
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
     * 权限缓存前缀
     */
    private const PERMISSION_CACHE_PREFIX = 'ws_perm_';
    
    /**
     * Token 默认有效期（秒）
     */
    private const TOKEN_EXPIRE = 7200; // 2小时
    
    /**
     * 用户信息缓存有效期（秒）
     */
    private const USER_CACHE_EXPIRE = 1800; // 30分钟
    
    /**
     * 权限缓存有效期（秒）
     */
    private const PERMISSION_CACHE_EXPIRE = 300; // 5分钟

    /**
     * 用户状态常量
     */
    const USER_STATUS_ACTIVE = 1;
    const USER_STATUS_INACTIVE = 0;
    const USER_STATUS_BANNED = -1;

    /**
     * 用户等级常量
     */
    const USER_LEVEL_NORMAL = 1;
    const USER_LEVEL_VIP = 3;
    const USER_LEVEL_MODERATOR = 5;
    const USER_LEVEL_ADMIN = 9;

    /**
     * 认证统计
     */
    private static $stats = [
        'total_auth_attempts' => 0,
        'successful_auths' => 0,
        'failed_auths' => 0,
        'token_validations' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'start_time' => 0
    ];

    /**
     * 初始化认证管理器
     */
    public static function init()
    {
        self::$stats['start_time'] = time();
        echo "[AuthManager] 认证管理器初始化完成\n";
    }

    /**
     * 验证用户Token（主要方法）
     * @param int $userId 用户ID
     * @param string $token 用户token
     * @return array|null 返回用户信息或null
     */
    public static function validateUserToken($userId, $token)
    {
        self::$stats['total_auth_attempts']++;
        self::$stats['token_validations']++;

        if (empty($token) || $userId <= 0) {
            self::$stats['failed_auths']++;
            return null;
        }

        try {
            // 首先从缓存获取
            $cacheKey = self::TOKEN_CACHE_PREFIX . md5($userId . '_' . $token);
            $userInfo = Cache::get($cacheKey);
            
            if ($userInfo !== null) {
                self::$stats['cache_hits']++;
                
                if ($userInfo === false) {
                    // 缓存的无效token
                    self::$stats['failed_auths']++;
                    return null;
                }
                
                // 验证用户状态
                if (self::isUserActive($userId)) {
                    self::$stats['successful_auths']++;
                    return $userInfo;
                } else {
                    // 用户被禁用，清除缓存
                    Cache::delete($cacheKey);
                    self::$stats['failed_auths']++;
                    return null;
                }
            }
            
            self::$stats['cache_misses']++;
            
            // 缓存中没有，进行token验证
            $userInfo = self::validateTokenFromDatabase($userId, $token);
            
            if ($userInfo) {
                // 缓存用户信息
                Cache::set($cacheKey, $userInfo, self::TOKEN_EXPIRE);
                self::$stats['successful_auths']++;
                return $userInfo;
            } else {
                // 缓存无效结果，避免重复查询
                Cache::set($cacheKey, false, 300); // 5分钟
                self::$stats['failed_auths']++;
                return null;
            }
            
        } catch (\Exception $e) {
            self::$stats['failed_auths']++;
            Log::error('Token验证异常', [
                'user_id' => $userId,
                'token' => substr($token, 0, 10) . '...',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 从数据库验证Token
     * @param int $userId 用户ID
     * @param string $token 用户token
     * @return array|null
     */
    private static function validateTokenFromDatabase($userId, $token)
    {
        try {
            // 方案1：如果有专门的token表
            $tokenExists = self::checkTokenTable();
            
            if ($tokenExists) {
                $tokenRecord = Db::name('user_tokens')
                    ->where('user_id', $userId)
                    ->where('token', $token)
                    ->where('expires_at', '>', date('Y-m-d H:i:s'))
                    ->where('status', 1)
                    ->find();
                    
                if (!$tokenRecord) {
                    return null;
                }
            } else {
                // 方案2：简化验证 - 基于用户ID生成的token
                $expectedToken = self::generateSimpleToken($userId);
                if ($token !== $expectedToken) {
                    return null;
                }
            }

            // 获取用户信息
            $user = self::getUserFromDatabase($userId);
            
            if ($user && $user['status'] == self::USER_STATUS_ACTIVE) {
                return [
                    'user_id' => $user['id'],
                    'username' => $user['username'] ?? '',
                    'nickname' => $user['nickname'] ?? $user['username'] ?? '',
                    'avatar' => $user['avatar'] ?? '',
                    'level' => (int)($user['level'] ?? self::USER_LEVEL_NORMAL),
                    'status' => (int)$user['status'],
                    'money_balance' => (float)($user['money_balance'] ?? 0),
                    'last_login' => $user['last_login_time'] ?? null,
                    'created_at' => $user['created_at'] ?? null
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('数据库Token验证失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 从数据库获取用户信息
     * @param int $userId
     * @return array|null
     */
    private static function getUserFromDatabase($userId)
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
                        return $user;
                    }
                } catch (\Exception $e) {
                    // 表不存在，尝试下一个
                    continue;
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('获取用户信息失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 检查是否存在token表
     * @return bool
     */
    private static function checkTokenTable()
    {
        try {
            $tables = Db::query("SHOW TABLES LIKE '%token%'");
            return !empty($tables);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 生成简单的token（基于用户ID）
     * @param int $userId
     * @return string
     */
    private static function generateSimpleToken($userId)
    {
        // 简单的token生成策略：user_id + 固定密钥的MD5
        $secret = 'sicbo_websocket_secret_2024'; // 可配置
        return md5($userId . '_' . $secret . '_websocket');
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
                $user = self::getUserFromDatabase($userId);
                $status = $user ? (int)$user['status'] : self::USER_STATUS_INACTIVE;
                
                // 缓存10分钟
                Cache::set($cacheKey, $status, 600);
            }
            
            return $status === self::USER_STATUS_ACTIVE;
            
        } catch (\Exception $e) {
            Log::error('检查用户状态失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
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
            $cacheKey = self::PERMISSION_CACHE_PREFIX . "{$userId}_{$permission}";
            if ($tableId) {
                $cacheKey .= "_{$tableId}";
            }
            
            $hasPermission = Cache::get($cacheKey);
            
            if ($hasPermission === null) {
                $hasPermission = self::validatePermission($userId, $permission, $tableId);
                
                // 缓存权限结果5分钟
                Cache::set($cacheKey, $hasPermission, self::PERMISSION_CACHE_EXPIRE);
            }
            
            return (bool)$hasPermission;
            
        } catch (\Exception $e) {
            Log::error('权限检查失败', [
                'user_id' => $userId,
                'permission' => $permission,
                'table_id' => $tableId,
                'error' => $e->getMessage()
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
            $user = self::getUserFromDatabase($userId);
            if (!$user || $user['status'] != self::USER_STATUS_ACTIVE) {
                return false;
            }

            $userLevel = (int)($user['level'] ?? self::USER_LEVEL_NORMAL);

            // 根据权限类型检查
            switch ($permission) {
                case 'join_table':
                    // 加入台桌权限
                    if ($tableId) {
                        // 检查台桌是否允许该用户加入
                        $table = Db::name('dianji_table')
                            ->where('id', $tableId)
                            ->where('status', 1)
                            ->find();
                        return $table !== null;
                    }
                    return $userLevel >= self::USER_LEVEL_NORMAL;
                    
                case 'bet':
                    // 投注权限：所有正常用户都有
                    return $userLevel >= self::USER_LEVEL_NORMAL && !self::isUserMuted($userId);
                    
                case 'admin':
                    // 管理员权限
                    return $userLevel >= self::USER_LEVEL_ADMIN;
                    
                case 'moderator':
                    // 版主权限
                    return $userLevel >= self::USER_LEVEL_MODERATOR;
                    
                case 'vip':
                    // VIP权限
                    return $userLevel >= self::USER_LEVEL_VIP;
                    
                case 'chat':
                    // 聊天权限
                    return $userLevel >= self::USER_LEVEL_NORMAL && !self::isUserMuted($userId);
                    
                case 'view_balance':
                    // 查看余额权限
                    return $userLevel >= self::USER_LEVEL_NORMAL;
                    
                case 'view_history':
                    // 查看历史权限
                    return $userLevel >= self::USER_LEVEL_NORMAL;
                    
                default:
                    // 未知权限默认拒绝
                    return false;
            }
            
        } catch (\Exception $e) {
            Log::error('权限验证失败', [
                'user_id' => $userId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
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
            // 检查是否有禁言表
            $tables = Db::query("SHOW TABLES LIKE '%mute%'");
            if (empty($tables)) {
                return false;
            }

            $muteRecord = Db::name('user_mutes')
                ->where('user_id', $userId)
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->where('status', 1)
                ->find();
                
            return $muteRecord !== null;
            
        } catch (\Exception $e) {
            Log::error('检查禁言状态失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
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
            $token = 'ws_' . $userId . '_' . time() . '_' . uniqid() . '_' . md5($deviceId . $userId);
            
            // 保存到数据库（如果有token表）
            $tokenExists = self::checkTokenTable();
            
            if ($tokenExists) {
                $tokenData = [
                    'user_id' => $userId,
                    'token' => $token,
                    'device_id' => $deviceId,
                    'expires_at' => date('Y-m-d H:i:s', time() + $expireTime),
                    'created_at' => date('Y-m-d H:i:s'),
                    'status' => 1
                ];
                
                try {
                    Db::name('user_tokens')->insert($tokenData);
                } catch (\Exception $e) {
                    // 如果插入失败，使用简单token
                    $token = self::generateSimpleToken($userId);
                }
            } else {
                // 使用简单token
                $token = self::generateSimpleToken($userId);
            }
            
            // 缓存Token信息
            $userInfo = self::getUserInfo($userId);
            if ($userInfo) {
                $cacheKey = self::TOKEN_CACHE_PREFIX . md5($userId . '_' . $token);
                Cache::set($cacheKey, $userInfo, $expireTime);
            }
            
            return $token;
            
        } catch (\Exception $e) {
            Log::error('生成Token失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
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
                $user = self::getUserFromDatabase($userId);
                    
                if ($user) {
                    $userInfo = [
                        'user_id' => $user['id'],
                        'username' => $user['username'] ?? '',
                        'nickname' => $user['nickname'] ?? $user['username'] ?? '',
                        'avatar' => $user['avatar'] ?? '',
                        'level' => (int)($user['level'] ?? self::USER_LEVEL_NORMAL),
                        'status' => (int)$user['status'],
                        'money_balance' => (float)($user['money_balance'] ?? 0),
                        'created_at' => $user['created_at'] ?? null,
                        'last_login' => $user['last_login_time'] ?? null
                    ];
                    
                    // 缓存30分钟
                    Cache::set($cacheKey, $userInfo, self::USER_CACHE_EXPIRE);
                }
            }
            
            return $userInfo;
            
        } catch (\Exception $e) {
            Log::error('获取用户信息失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
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
                $user = self::getUserFromDatabase($userId);
                $balance = $user ? (float)($user['money_balance'] ?? 0) : 0.0;
                
                // 缓存5分钟
                Cache::set($cacheKey, $balance, 300);
            }
            
            return (float)$balance;
            
        } catch (\Exception $e) {
            Log::error('获取用户余额失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * 验证Token（兼容多种验证方式）
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
            // 如果提供了用户ID，直接验证
            if ($userId) {
                return self::validateUserToken($userId, $token);
            }

            // 尝试从token中解析用户ID
            if (preg_match('/^ws_(\d+)_/', $token, $matches)) {
                $parsedUserId = (int)$matches[1];
                return self::validateUserToken($parsedUserId, $token);
            }

            // 检查是否为简单token格式
            foreach (range(1, 10000) as $testUserId) {
                $expectedToken = self::generateSimpleToken($testUserId);
                if ($token === $expectedToken) {
                    return self::validateUserToken($testUserId, $token);
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Token验证异常', [
                'token' => substr($token, 0, 10) . '...',
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
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
            $cacheKeys = Cache::get('token_cache_keys', []);
            foreach ($cacheKeys as $key) {
                if (strpos($key, md5($token)) !== false) {
                    Cache::delete($key);
                }
            }
            
            // 从数据库中标记为无效（如果有token表）
            $tokenExists = self::checkTokenTable();
            if ($tokenExists) {
                Db::name('user_tokens')
                    ->where('token', $token)
                    ->update([
                        'status' => 0,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('撤销Token失败', [
                'token' => substr($token, 0, 10) . '...',
                'error' => $e->getMessage()
            ]);
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
            $tokenExists = self::checkTokenTable();
            if ($tokenExists) {
                Db::name('user_tokens')
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
            Log::error('撤销用户Token失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
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
            
            // 清除权限相关缓存
            $permissions = ['join_table', 'bet', 'admin', 'moderator', 'vip', 'chat', 'view_balance', 'view_history'];
            foreach ($permissions as $perm) {
                Cache::delete(self::PERMISSION_CACHE_PREFIX . "{$userId}_{$perm}");
            }
            
        } catch (\Exception $e) {
            Log::error('清除用户缓存失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
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
            $user = self::getUserFromDatabase($userId);
            if (!$user) {
                return false;
            }

            // 尝试更新用户表
            $tableNames = ['common_user', 'users', 'user', 'dianji_user'];
            
            foreach ($tableNames as $tableName) {
                try {
                    $result = Db::name($tableName)
                        ->where('id', $userId)
                        ->update([
                            'last_login_time' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        
                    if ($result !== false) {
                        // 清除用户信息缓存，下次获取时会重新缓存
                        Cache::delete(self::USER_CACHE_PREFIX . "info_{$userId}");
                        return true;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('更新最后登录时间失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
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
            $tokenExists = self::checkTokenTable();
            if (!$tokenExists) {
                return null;
            }
            
            $tokenRecord = Db::name('user_tokens')
                ->where('token', $token)
                ->find();
                
            return $tokenRecord;
            
        } catch (\Exception $e) {
            Log::error('获取Token信息失败', [
                'token' => substr($token, 0, 10) . '...',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 检查用户权限（快捷方法）
     * @param int $userId
     * @param string $permission
     * @param int|null $tableId
     * @return bool
     */
    public static function checkUserPermission($userId, $permission, $tableId = null)
    {
        return self::checkPermission($userId, $permission, $tableId);
    }

    /**
     * 获取用户权限列表
     * @param int $userId
     * @return array
     */
    public static function getUserPermissions($userId)
    {
        try {
            $user = self::getUserFromDatabase($userId);
            if (!$user || $user['status'] != self::USER_STATUS_ACTIVE) {
                return [];
            }

            $userLevel = (int)($user['level'] ?? self::USER_LEVEL_NORMAL);
            $permissions = [];

            // 基础权限
            if ($userLevel >= self::USER_LEVEL_NORMAL) {
                $permissions[] = 'join_table';
                $permissions[] = 'view_balance';
                $permissions[] = 'view_history';
                
                if (!self::isUserMuted($userId)) {
                    $permissions[] = 'bet';
                    $permissions[] = 'chat';
                }
            }

            // VIP权限
            if ($userLevel >= self::USER_LEVEL_VIP) {
                $permissions[] = 'vip';
            }

            // 版主权限
            if ($userLevel >= self::USER_LEVEL_MODERATOR) {
                $permissions[] = 'moderator';
            }

            // 管理员权限
            if ($userLevel >= self::USER_LEVEL_ADMIN) {
                $permissions[] = 'admin';
            }

            return $permissions;
            
        } catch (\Exception $e) {
            Log::error('获取用户权限失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 获取认证统计信息
     * @return array
     */
    public static function getStats()
    {
        $runtime = time() - self::$stats['start_time'];
        
        return array_merge(self::$stats, [
            'runtime_seconds' => $runtime,
            'auth_rate' => self::$stats['total_auth_attempts'] > 0 
                ? round((self::$stats['successful_auths'] / self::$stats['total_auth_attempts']) * 100, 2) 
                : 0,
            'cache_hit_rate' => (self::$stats['cache_hits'] + self::$stats['cache_misses']) > 0 
                ? round((self::$stats['cache_hits'] / (self::$stats['cache_hits'] + self::$stats['cache_misses'])) * 100, 2) 
                : 0,
            'auths_per_minute' => $runtime > 0 ? round((self::$stats['total_auth_attempts'] / $runtime) * 60, 2) : 0,
            'update_time' => time()
        ]);
    }

    /**
     * 重置统计信息
     */
    public static function resetStats()
    {
        self::$stats = [
            'total_auth_attempts' => 0,
            'successful_auths' => 0,
            'failed_auths' => 0,
            'token_validations' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'start_time' => time()
        ];
        
        echo "[AuthManager] 认证统计已重置\n";
    }

    /**
     * 获取在线用户列表
     * @param int $limit
     * @return array
     */
    public static function getOnlineUsers($limit = 100)
    {
        try {
            // 从连接管理器获取在线用户ID
            $onlineStats = \app\websocket\ConnectionManager::getOnlineStats();
            
            // 这里可以扩展获取详细的在线用户信息
            return [
                'total_online' => $onlineStats['authenticated_users'] ?? 0,
                'timestamp' => time()
            ];
            
        } catch (\Exception $e) {
            Log::error('获取在线用户失败', ['error' => $e->getMessage()]);
            return [
                'total_online' => 0,
                'timestamp' => time(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 批量检查用户权限
     * @param array $userIds
     * @param string $permission
     * @param int|null $tableId
     * @return array
     */
    public static function batchCheckPermission(array $userIds, $permission, $tableId = null)
    {
        $results = [];
        
        foreach ($userIds as $userId) {
            $results[$userId] = self::checkPermission($userId, $permission, $tableId);
        }
        
        return $results;
    }

    /**
     * 创建测试用户Token（仅用于开发测试）
     * @param int $userId
     * @return string
     */
    public static function createTestToken($userId)
    {
        if (!defined('APP_DEBUG') || !APP_DEBUG) {
            throw new \Exception('测试Token只能在调试模式下生成');
        }
        
        return self::generateSimpleToken($userId);
    }

    /**
     * 验证简单Token（用于快速验证）
     * @param int $userId
     * @param string $token
     * @return bool
     */
    public static function validateSimpleToken($userId, $token)
    {
        $expectedToken = self::generateSimpleToken($userId);
        return $token === $expectedToken;
    }

    /**
     * 清理过期缓存
     */
    public static function cleanupExpiredCache()
    {
        try {
            // 这里可以实现清理过期缓存的逻辑
            // ThinkPHP的缓存会自动处理过期，一般不需要手动清理
            
            echo "[AuthManager] 缓存清理完成\n";
            
        } catch (\Exception $e) {
            Log::error('清理缓存失败', ['error' => $e->getMessage()]);
        }
    }
}