<?php

namespace app\websocket;

use think\facade\Log;

/**
 * Redis游戏状态管理器 - 简化版
 * 只保留7个核心方法，专注于读取Redis中的游戏状态数据
 * 删除执行命令、获取键列表、批量操作等复杂功能
 * 适配 PHP 7.3 + ThinkPHP6
 */
class RedisGameManager
{
    /**
     * Redis连接实例
     * @var \Redis|null
     */
    private static $redis = null;

    /**
     * Redis配置
     * @var array
     */
    private static $config = [];

    /**
     * Redis键前缀
     * @var string
     */
    private static $prefix = 'sicbo:';

    /**
     * 连接状态
     * @var bool
     */
    private static $connected = false;

    /**
     * 数据缓存（简单内存缓存）
     * @var array
     */
    private static $cache = [];

    /**
     * 缓存过期时间（秒）
     * @var int
     */
    private static $cacheExpire = 5;

    /**
     * 1. 初始化Redis连接
     * @param array $config Redis配置
     */
    public static function init(array $config = [])
    {
        self::$config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'timeout' => 5,
            'prefix' => 'sicbo:'
        ], $config);

        self::$prefix = self::$config['prefix'];
        
        try {
            self::connect();
            echo "[RedisGameManager] Redis连接初始化完成\n";
        } catch (\Exception $e) {
            echo "[ERROR] Redis初始化失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 2. 获取游戏状态
     * @param int $tableId 台桌ID
     * @return array|null
     */
    public static function getGameStatus($tableId)
    {
        $cacheKey = "game_status_{$tableId}";
        
        // 检查内存缓存
        if (self::isCacheValid($cacheKey)) {
            return self::$cache[$cacheKey]['data'];
        }

        try {
            self::ensureConnection();
            
            $redisKey = self::$prefix . "table:{$tableId}:status";
            $data = self::$redis->get($redisKey);
            
            if ($data === false) {
                self::setCache($cacheKey, null);
                return null;
            }

            $gameStatus = json_decode($data, true);
            if (!$gameStatus) {
                self::setCache($cacheKey, null);
                return null;
            }

            // 添加计算字段
            $gameStatus = self::enrichGameStatus($gameStatus);
            
            self::setCache($cacheKey, $gameStatus);
            return $gameStatus;

        } catch (\Exception $e) {
            Log::error('获取游戏状态失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 3. 获取开奖结果
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @return array|null
     */
    public static function getGameResult($tableId, $gameNumber)
    {
        $cacheKey = "game_result_{$tableId}_{$gameNumber}";
        
        // 检查内存缓存
        if (self::isCacheValid($cacheKey)) {
            return self::$cache[$cacheKey]['data'];
        }

        try {
            self::ensureConnection();
            
            $redisKey = self::$prefix . "table:{$tableId}:result:{$gameNumber}";
            $data = self::$redis->get($redisKey);
            
            if ($data === false) {
                self::setCache($cacheKey, null);
                return null;
            }

            $gameResult = json_decode($data, true);
            if (!$gameResult) {
                self::setCache($cacheKey, null);
                return null;
            }

            // 添加计算字段
            $gameResult = self::enrichGameResult($gameResult);
            
            self::setCache($cacheKey, $gameResult);
            return $gameResult;

        } catch (\Exception $e) {
            Log::error('获取开奖结果失败', [
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 4. 获取用户中奖信息
     * @param int $userId 用户ID
     * @param string $gameNumber 游戏局号
     * @return array|null
     */
    public static function getWinInfo($userId, $gameNumber)
    {
        $cacheKey = "win_info_{$userId}_{$gameNumber}";
        
        // 检查内存缓存
        if (self::isCacheValid($cacheKey)) {
            return self::$cache[$cacheKey]['data'];
        }

        try {
            self::ensureConnection();
            
            $redisKey = self::$prefix . "user:{$userId}:win:{$gameNumber}";
            $data = self::$redis->get($redisKey);
            
            if ($data === false) {
                self::setCache($cacheKey, null);
                return null;
            }

            $winInfo = json_decode($data, true);
            if (!$winInfo) {
                self::setCache($cacheKey, null);
                return null;
            }

            self::setCache($cacheKey, $winInfo);
            return $winInfo;

        } catch (\Exception $e) {
            Log::error('获取中奖信息失败', [
                'user_id' => $userId,
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 5. 测试Redis连接
     * @return bool
     */
    public static function testConnection()
    {
        try {
            self::ensureConnection();
            $result = self::$redis->ping();
            return $result === '+PONG' || $result === true;
        } catch (\Exception $e) {
            Log::error('Redis连接测试失败', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 6. 清理内存缓存
     */
    public static function cleanupCache()
    {
        $currentTime = time();
        $cleanedCount = 0;
        
        foreach (self::$cache as $key => $item) {
            if (($currentTime - $item['time']) >= self::$cacheExpire) {
                unset(self::$cache[$key]);
                $cleanedCount++;
            }
        }
        
        if ($cleanedCount > 0) {
            echo "[RedisGameManager] 清理缓存: 删除{$cleanedCount}个过期项\n";
        }
    }

    /**
     * 7. 关闭Redis连接
     */
    public static function close()
    {
        try {
            if (self::$redis instanceof \Redis && self::$connected) {
                self::$redis->close();
                self::$connected = false;
                echo "[RedisGameManager] Redis连接已关闭\n";
            }
        } catch (\Exception $e) {
            Log::error('关闭Redis连接失败', ['error' => $e->getMessage()]);
        }
    }

    // ========================================
    // 私有辅助方法
    // ========================================

    /**
     * 建立Redis连接
     */
    private static function connect()
    {
        try {
            if (self::$redis instanceof \Redis && self::$connected) {
                return; // 已连接
            }

            self::$redis = new \Redis();
            
            $connected = self::$redis->connect(
                self::$config['host'],
                self::$config['port'],
                self::$config['timeout']
            );

            if (!$connected) {
                throw new \Exception('Redis连接失败');
            }

            // 设置密码（如果有）
            if (!empty(self::$config['password'])) {
                $authResult = self::$redis->auth(self::$config['password']);
                if (!$authResult) {
                    throw new \Exception('Redis认证失败');
                }
            }

            // 选择数据库
            self::$redis->select(self::$config['database']);

            self::$connected = true;

        } catch (\Exception $e) {
            self::$connected = false;
            throw new \Exception('Redis连接异常: ' . $e->getMessage());
        }
    }

    /**
     * 确保Redis连接可用
     */
    private static function ensureConnection()
    {
        if (!self::$connected || !self::$redis) {
            self::connect();
        }

        // 测试连接
        try {
            self::$redis->ping();
        } catch (\Exception $e) {
            self::$connected = false;
            self::connect();
        }
    }

    /**
     * 丰富游戏状态数据（添加计算字段）
     * @param array $gameStatus
     * @return array
     */
    private static function enrichGameStatus(array $gameStatus)
    {
        // 计算剩余时间
        if (isset($gameStatus['betting_end_time']) && $gameStatus['status'] === 'betting') {
            $gameStatus['remaining_time'] = max(0, $gameStatus['betting_end_time'] - time());
        } else {
            $gameStatus['remaining_time'] = 0;
        }

        // 添加状态描述
        $statusMap = [
            'waiting' => '等待开始',
            'betting' => '投注中',
            'dealing' => '开奖中',
            'result' => '结果公布'
        ];
        $gameStatus['status_text'] = $statusMap[$gameStatus['status']] ?? '未知状态';

        // 确保必要字段存在
        $defaultFields = [
            'table_id' => 0,
            'game_number' => '',
            'round_number' => 0,
            'status' => 'waiting',
            'start_time' => 0,
            'total_time' => 30,
            'betting_end_time' => 0,
            'update_time' => time()
        ];

        return array_merge($defaultFields, $gameStatus);
    }

    /**
     * 丰富开奖结果数据（添加计算字段）
     * @param array $gameResult
     * @return array
     */
    private static function enrichGameResult(array $gameResult)
    {
        // 确保骰子数据存在
        $dice1 = (int)($gameResult['dice1'] ?? 1);
        $dice2 = (int)($gameResult['dice2'] ?? 1);
        $dice3 = (int)($gameResult['dice3'] ?? 1);

        // 计算总点数
        $totalPoints = $dice1 + $dice2 + $dice3;
        $gameResult['total_points'] = $totalPoints;

        // 计算大小单双
        $gameResult['is_big'] = $totalPoints >= 11 && $totalPoints <= 17;
        $gameResult['is_small'] = $totalPoints >= 4 && $totalPoints <= 10;
        $gameResult['is_odd'] = $totalPoints % 2 === 1;
        $gameResult['is_even'] = $totalPoints % 2 === 0;

        // 检查三同号
        $gameResult['has_triple'] = ($dice1 === $dice2 && $dice2 === $dice3);
        if ($gameResult['has_triple']) {
            $gameResult['triple_number'] = $dice1;
        }

        // 检查对子
        $gameResult['has_pair'] = !$gameResult['has_triple'] && (
            ($dice1 === $dice2) || ($dice2 === $dice3) || ($dice1 === $dice3)
        );

        // 计算中奖投注类型
        $gameResult['winning_bets'] = self::calculateWinningBets($dice1, $dice2, $dice3);

        // 添加结果描述
        $bigSmall = $gameResult['is_big'] ? '大' : '小';
        $oddEven = $gameResult['is_odd'] ? '单' : '双';
        $gameResult['result_text'] = "{$dice1}-{$dice2}-{$dice3}, 总点数{$totalPoints}, {$bigSmall}/{$oddEven}";

        return $gameResult;
    }

    /**
     * 计算中奖投注类型
     * @param int $dice1
     * @param int $dice2
     * @param int $dice3
     * @return array
     */
    private static function calculateWinningBets($dice1, $dice2, $dice3)
    {
        $winningBets = [];
        $totalPoints = $dice1 + $dice2 + $dice3;

        // 大小
        if ($totalPoints >= 11 && $totalPoints <= 17) {
            $winningBets[] = 'big';
        } else {
            $winningBets[] = 'small';
        }

        // 单双
        if ($totalPoints % 2 === 1) {
            $winningBets[] = 'odd';
        } else {
            $winningBets[] = 'even';
        }

        // 总和
        $winningBets[] = "total-{$totalPoints}";

        // 单骰
        $diceCounts = array_count_values([$dice1, $dice2, $dice3]);
        foreach ($diceCounts as $dice => $count) {
            $winningBets[] = "single-{$dice}";
        }

        // 对子和三同号
        foreach ($diceCounts as $dice => $count) {
            if ($count === 2) {
                $winningBets[] = "pair-{$dice}";
            } elseif ($count === 3) {
                $winningBets[] = "triple-{$dice}";
                $winningBets[] = "any-triple";
            }
        }

        // 组合
        $uniqueDices = array_unique([$dice1, $dice2, $dice3]);
        if (count($uniqueDices) >= 2) {
            sort($uniqueDices);
            for ($i = 0; $i < count($uniqueDices); $i++) {
                for ($j = $i + 1; $j < count($uniqueDices); $j++) {
                    $winningBets[] = "combo-{$uniqueDices[$i]}-{$uniqueDices[$j]}";
                }
            }
        }

        return array_unique($winningBets);
    }

    /**
     * 检查内存缓存是否有效
     * @param string $key
     * @return bool
     */
    private static function isCacheValid($key)
    {
        if (!isset(self::$cache[$key])) {
            return false;
        }

        $cacheItem = self::$cache[$key];
        return (time() - $cacheItem['time']) < self::$cacheExpire;
    }

    /**
     * 设置内存缓存
     * @param string $key
     * @param mixed $data
     */
    private static function setCache($key, $data)
    {
        self::$cache[$key] = [
            'data' => $data,
            'time' => time()
        ];
    }

    /**
     * 获取连接状态
     * @return bool
     */
    public static function isConnected()
    {
        return self::$connected;
    }

    /**
     * 获取Redis配置信息
     * @return array
     */
    public static function getConfig()
    {
        return [
            'host' => self::$config['host'],
            'port' => self::$config['port'],
            'database' => self::$config['database'],
            'prefix' => self::$prefix,
            'connected' => self::$connected,
            'cache_count' => count(self::$cache),
            'cache_expire' => self::$cacheExpire
        ];
    }

    /**
     * 获取最近的游戏结果（限制数量，避免性能问题）
     * @param int $tableId 台桌ID
     * @param int $limit 数量限制（最大10）
     * @return array
     */
    public static function getRecentResults($tableId, $limit = 5)
    {
        try {
            self::ensureConnection();
            
            // 限制最大查询数量，避免性能问题
            $limit = min($limit, 10);
            
            $pattern = self::$prefix . "table:{$tableId}:result:*";
            $keys = self::$redis->keys($pattern);
            
            if (empty($keys)) {
                return [];
            }

            // 按键名排序（包含时间信息）
            rsort($keys);
            
            // 限制数量
            $keys = array_slice($keys, 0, $limit);
            
            $results = [];
            foreach ($keys as $key) {
                $data = self::$redis->get($key);
                if ($data !== false) {
                    $result = json_decode($data, true);
                    if ($result) {
                        $results[] = self::enrichGameResult($result);
                    }
                }
            }

            return $results;

        } catch (\Exception $e) {
            Log::error('获取最近游戏结果失败', [
                'table_id' => $tableId,
                'limit' => $limit,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}