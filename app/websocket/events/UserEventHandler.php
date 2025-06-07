<?php

namespace app\websocket\events;

use Workerman\Connection\TcpConnection;
use app\websocket\ConnectionManager;
use app\websocket\MessageHandler;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * 用户事件处理器
 * 处理用户信息、余额查询等消息
 */
class UserEventHandler
{
    /**
     * 处理用户相关消息
     * @param TcpConnection $connection
     * @param array $message
     */
    public static function handle(TcpConnection $connection, array $message)
    {
        $messageType = $message['type'];
        
        switch ($messageType) {
            case 'user_info':
                self::handleGetUserInfo($connection, $message);
                break;
                
            case 'user_balance':
                self::handleGetUserBalance($connection, $message);
                break;
                
            case 'balance_update':
                self::handleBalanceUpdate($connection, $message);
                break;
                
            case 'user_settings':
                self::handleUserSettings($connection, $message);
                break;
                
            case 'online_users':
                self::handleGetOnlineUsers($connection, $message);
                break;
                
            default:
                MessageHandler::sendError($connection, '未知的用户消息类型');
                break;
        }
    }

    /**
     * 处理获取用户信息请求
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleGetUserInfo(TcpConnection $connection, array $message)
    {
        $userId = MessageHandler::getConnectionUserId($connection);

        if (!$userId) {
            MessageHandler::sendError($connection, '请先登录');
            return;
        }

        try {
            // 获取用户详细信息
            $userInfo = self::getUserInfo($userId);
            
            if (!$userInfo) {
                MessageHandler::sendError($connection, '用户信息不存在');
                return;
            }

            // 获取用户余额
            $balance = self::getUserBalance($userId);
            
            // 获取用户统计信息
            $userStats = self::getUserStats($userId);

            // 发送用户信息响应
            MessageHandler::sendSuccess($connection, 'user_info_response', [
                'user_info' => $userInfo,
                'balance' => $balance,
                'stats' => $userStats
            ], '用户信息获取成功');

        } catch (\Exception $e) {
            Log::error('获取用户信息异常: ' . $e->getMessage(), [
                'user_id' => $userId
            ]);
            
            MessageHandler::sendError($connection, '获取用户信息失败');
        }
    }

    /**
     * 处理获取用户余额请求
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleGetUserBalance(TcpConnection $connection, array $message)
    {
        $userId = MessageHandler::getConnectionUserId($connection);

        if (!$userId) {
            MessageHandler::sendError($connection, '请先登录');
            return;
        }

        try {
            // 获取用户余额信息
            $balanceInfo = self::getDetailedBalance($userId);

            // 发送余额信息响应
            MessageHandler::sendSuccess($connection, 'user_balance_response', [
                'balance_info' => $balanceInfo
            ], '余额信息获取成功');

        } catch (\Exception $e) {
            Log::error('获取用户余额异常: ' . $e->getMessage(), [
                'user_id' => $userId
            ]);
            
            MessageHandler::sendError($connection, '获取余额信息失败');
        }
    }

    /**
     * 处理余额更新通知
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleBalanceUpdate(TcpConnection $connection, array $message)
    {
        $userId = MessageHandler::getConnectionUserId($connection);

        if (!$userId) {
            MessageHandler::sendError($connection, '请先登录');
            return;
        }

        try {
            // 获取最新余额
            $balance = self::getUserBalance($userId);
            
            // 获取最近的余额变动记录
            $recentLogs = self::getRecentBalanceLogs($userId, 5);

            // 发送余额更新响应
            MessageHandler::sendSuccess($connection, 'balance_update_response', [
                'current_balance' => $balance,
                'recent_logs' => $recentLogs,
                'update_time' => time()
            ], '余额信息已更新');

        } catch (\Exception $e) {
            Log::error('余额更新处理异常: ' . $e->getMessage(), [
                'user_id' => $userId
            ]);
            
            MessageHandler::sendError($connection, '余额更新失败');
        }
    }

    /**
     * 处理用户设置
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleUserSettings(TcpConnection $connection, array $message)
    {
        $userId = MessageHandler::getConnectionUserId($connection);

        if (!$userId) {
            MessageHandler::sendError($connection, '请先登录');
            return;
        }

        $action = $message['action'] ?? 'get';

        try {
            if ($action === 'get') {
                // 获取用户设置
                $settings = self::getUserSettings($userId);
                
                MessageHandler::sendSuccess($connection, 'user_settings_response', [
                    'settings' => $settings
                ], '用户设置获取成功');
                
            } elseif ($action === 'update') {
                // 更新用户设置
                if (!isset($message['settings']) || !is_array($message['settings'])) {
                    MessageHandler::sendError($connection, '设置数据格式错误');
                    return;
                }

                $success = self::updateUserSettings($userId, $message['settings']);
                
                if ($success) {
                    MessageHandler::sendSuccess($connection, 'user_settings_updated', [
                        'settings' => $message['settings']
                    ], '用户设置更新成功');
                } else {
                    MessageHandler::sendError($connection, '用户设置更新失败');
                }
            }

        } catch (\Exception $e) {
            Log::error('用户设置处理异常: ' . $e->getMessage(), [
                'user_id' => $userId,
                'action' => $action
            ]);
            
            MessageHandler::sendError($connection, '用户设置处理失败');
        }
    }

    /**
     * 处理获取在线用户请求
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleGetOnlineUsers(TcpConnection $connection, array $message)
    {
        $tableId = MessageHandler::getConnectionTableId($connection);

        if (!$tableId) {
            MessageHandler::sendError($connection, '请先加入台桌');
            return;
        }

        try {
            // 获取台桌在线用户数
            $onlineCount = ConnectionManager::getTableConnectionCount($tableId);
            
            // 获取在线统计
            $onlineStats = ConnectionManager::getOnlineStats();

            // 发送在线用户信息响应
            MessageHandler::sendSuccess($connection, 'online_users_response', [
                'table_id' => $tableId,
                'table_online_count' => $onlineCount,
                'global_stats' => $onlineStats
            ], '在线用户信息获取成功');

        } catch (\Exception $e) {
            Log::error('获取在线用户异常: ' . $e->getMessage(), [
                'table_id' => $tableId
            ]);
            
            MessageHandler::sendError($connection, '获取在线用户信息失败');
        }
    }

    /**
     * 获取用户详细信息
     * @param int $userId
     * @return array|null
     */
    private static function getUserInfo($userId)
    {
        try {
            $cacheKey = "user_info_{$userId}";
            $userInfo = Cache::get($cacheKey);
            
            if (!$userInfo) {
                $user = Db::table('users')
                    ->where('id', $userId)
                    ->field('id,username,nickname,avatar,level,status,created_at,last_login_time')
                    ->find();
                    
                if ($user) {
                    $userInfo = $user;
                    Cache::set($cacheKey, $userInfo, 1800); // 30分钟缓存
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
    private static function getUserBalance($userId)
    {
        try {
            $cacheKey = "user_balance_{$userId}";
            $balance = Cache::get($cacheKey);
            
            if ($balance === null) {
                $userBalance = Db::table('user_balance')
                    ->where('user_id', $userId)
                    ->value('balance');
                    
                $balance = $userBalance ?? 0.00;
                Cache::set($cacheKey, $balance, 300); // 5分钟缓存
            }
            
            return (float)$balance;
            
        } catch (\Exception $e) {
            Log::error('获取用户余额失败: ' . $e->getMessage());
            return 0.00;
        }
    }

    /**
     * 获取详细余额信息
     * @param int $userId
     * @return array
     */
    private static function getDetailedBalance($userId)
    {
        try {
            $balanceRecord = Db::table('user_balance')
                ->where('user_id', $userId)
                ->find();
                
            if (!$balanceRecord) {
                return [
                    'balance' => 0.00,
                    'frozen_amount' => 0.00,
                    'available_balance' => 0.00,
                    'updated_at' => null
                ];
            }

            $balance = (float)$balanceRecord['balance'];
            $frozenAmount = (float)($balanceRecord['frozen_amount'] ?? 0);
            
            return [
                'balance' => $balance,
                'frozen_amount' => $frozenAmount,
                'available_balance' => $balance - $frozenAmount,
                'updated_at' => $balanceRecord['updated_at']
            ];
            
        } catch (\Exception $e) {
            Log::error('获取详细余额信息失败: ' . $e->getMessage());
            return [
                'balance' => 0.00,
                'frozen_amount' => 0.00,
                'available_balance' => 0.00,
                'updated_at' => null
            ];
        }
    }

    /**
     * 获取用户统计信息
     * @param int $userId
     * @return array
     */
    private static function getUserStats($userId)
    {
        try {
            $cacheKey = "user_stats_{$userId}";
            $stats = Cache::get($cacheKey);
            
            if (!$stats) {
                // 获取投注统计
                $betStats = Db::table('sicbo_bet_records')
                    ->where('user_id', $userId)
                    ->field([
                        'COUNT(*) as total_bets',
                        'SUM(bet_amount) as total_bet_amount',
                        'SUM(CASE WHEN is_win = 1 THEN 1 ELSE 0 END) as win_count',
                        'SUM(win_amount) as total_win_amount'
                    ])
                    ->find();

                $stats = [
                    'total_bets' => (int)($betStats['total_bets'] ?? 0),
                    'total_bet_amount' => (float)($betStats['total_bet_amount'] ?? 0),
                    'win_count' => (int)($betStats['win_count'] ?? 0),
                    'total_win_amount' => (float)($betStats['total_win_amount'] ?? 0),
                    'win_rate' => 0
                ];

                // 计算胜率
                if ($stats['total_bets'] > 0) {
                    $stats['win_rate'] = round(($stats['win_count'] / $stats['total_bets']) * 100, 2);
                }

                Cache::set($cacheKey, $stats, 600); // 10分钟缓存
            }
            
            return $stats;
            
        } catch (\Exception $e) {
            Log::error('获取用户统计失败: ' . $e->getMessage());
            return [
                'total_bets' => 0,
                'total_bet_amount' => 0.00,
                'win_count' => 0,
                'total_win_amount' => 0.00,
                'win_rate' => 0
            ];
        }
    }

    /**
     * 获取最近余额变动记录
     * @param int $userId
     * @param int $limit
     * @return array
     */
    private static function getRecentBalanceLogs($userId, $limit = 10)
    {
        try {
            $logs = Db::table('user_balance_log')
                ->where('user_id', $userId)
                ->field('type,amount,before_balance,after_balance,remark,created_at')
                ->order('created_at desc')
                ->limit($limit)
                ->select();
                
            return $logs ? $logs->toArray() : [];
            
        } catch (\Exception $e) {
            Log::error('获取余额变动记录失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取用户设置
     * @param int $userId
     * @return array
     */
    private static function getUserSettings($userId)
    {
        try {
            $cacheKey = "user_settings_{$userId}";
            $settings = Cache::get($cacheKey);
            
            if (!$settings) {
                $userSettings = Db::table('user_settings')
                    ->where('user_id', $userId)
                    ->value('settings');
                    
                if ($userSettings) {
                    $settings = json_decode($userSettings, true) ?: [];
                } else {
                    // 默认设置
                    $settings = [
                        'sound_enabled' => true,
                        'vibration_enabled' => true,
                        'auto_bet_enabled' => false,
                        'theme' => 'default',
                        'language' => 'zh-cn'
                    ];
                }
                
                Cache::set($cacheKey, $settings, 1800); // 30分钟缓存
            }
            
            return $settings;
            
        } catch (\Exception $e) {
            Log::error('获取用户设置失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 更新用户设置
     * @param int $userId
     * @param array $settings
     * @return bool
     */
    private static function updateUserSettings($userId, array $settings)
    {
        try {
            // 验证设置数据
            $allowedKeys = ['sound_enabled', 'vibration_enabled', 'auto_bet_enabled', 'theme', 'language'];
            $filteredSettings = array_intersect_key($settings, array_flip($allowedKeys));
            
            if (empty($filteredSettings)) {
                return false;
            }

            // 获取现有设置
            $currentSettings = self::getUserSettings($userId);
            
            // 合并设置
            $newSettings = array_merge($currentSettings, $filteredSettings);
            
            // 保存到数据库
            $exists = Db::table('user_settings')
                ->where('user_id', $userId)
                ->find();
                
            if ($exists) {
                Db::table('user_settings')
                    ->where('user_id', $userId)
                    ->update([
                        'settings' => json_encode($newSettings),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            } else {
                Db::table('user_settings')->insert([
                    'user_id' => $userId,
                    'settings' => json_encode($newSettings),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            // 清除缓存
            Cache::delete("user_settings_{$userId}");
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('更新用户设置失败: ' . $e->getMessage());
            return false;
        }
    }
}