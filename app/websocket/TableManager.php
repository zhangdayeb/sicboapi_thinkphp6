<?php

namespace app\websocket;

use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * 台桌管理器 - 基础版
 * 负责台桌基础信息管理和查询，专注于WebSocket需要的核心功能
 * 适配 PHP 7.3 + ThinkPHP6
 */
class TableManager
{
    /**
     * 台桌缓存前缀
     */
    const CACHE_PREFIX = 'ws_table_';
    
    /**
     * 缓存有效期（秒）
     */
    const CACHE_EXPIRE = 300; // 5分钟
    
    /**
     * 骰宝游戏类型ID
     */
    const SICBO_GAME_TYPE = 9;

    /**
     * 台桌状态常量
     */
    const STATUS_CLOSED = 0;    // 关闭
    const STATUS_OPEN = 1;      // 开放
    const STATUS_MAINTENANCE = 2; // 维护

    /**
     * 运行状态常量
     */
    const RUN_STATUS_WAITING = 0;  // 等待中
    const RUN_STATUS_BETTING = 1;  // 投注中
    const RUN_STATUS_DEALING = 2;  // 开奖中

    /**
     * 获取台桌基础信息
     * @param int $tableId 台桌ID
     * @return array|null
     */
    public static function getTableInfo($tableId)
    {
        if ($tableId <= 0) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . "info_{$tableId}";
        
        try {
            // 先从缓存获取
            $tableInfo = Cache::get($cacheKey);
            
            if ($tableInfo === null) {
                // 从数据库获取
                $table = Db::name('dianji_table')
                    ->where('id', $tableId)
                    ->where('game_type', self::SICBO_GAME_TYPE)
                    ->find();
                    
                if (!$table) {
                    // 缓存空结果，避免重复查询
                    Cache::set($cacheKey, false, 60);
                    return null;
                }

                // 格式化台桌信息
                $tableInfo = self::formatTableInfo($table);
                
                // 缓存台桌信息
                Cache::set($cacheKey, $tableInfo, self::CACHE_EXPIRE);
            } elseif ($tableInfo === false) {
                // 缓存的空结果
                return null;
            }
            
            return $tableInfo;
            
        } catch (\Exception $e) {
            Log::error('获取台桌信息失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 获取台桌列表
     * @param array $conditions 查询条件
     * @return array
     */
    public static function getTableList(array $conditions = [])
    {
        try {
            $query = Db::name('dianji_table')
                ->where('game_type', self::SICBO_GAME_TYPE);
                
            // 添加查询条件
            if (isset($conditions['status'])) {
                $query->where('status', $conditions['status']);
            }
            
            if (isset($conditions['run_status'])) {
                $query->where('run_status', $conditions['run_status']);
            }
            
            $tables = $query->field('id,table_title,status,run_status,min_bet,max_bet,created_time')
                ->order('id asc')
                ->select();
                
            if (!$tables) {
                return [];
            }

            // 格式化台桌列表
            $formattedTables = [];
            foreach ($tables as $table) {
                $formattedTables[] = self::formatTableInfo($table);
            }

            return $formattedTables;
            
        } catch (\Exception $e) {
            Log::error('获取台桌列表失败', [
                'conditions' => $conditions,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 检查台桌是否可用
     * @param int $tableId 台桌ID
     * @return bool
     */
    public static function isTableAvailable($tableId)
    {
        $tableInfo = self::getTableInfo($tableId);
        
        if (!$tableInfo) {
            return false;
        }

        // 检查台桌状态：必须是开放状态
        if ($tableInfo['status'] !== self::STATUS_OPEN) {
            return false;
        }

        // 检查游戏类型：必须是骰宝
        if ($tableInfo['game_type'] !== self::SICBO_GAME_TYPE) {
            return false;
        }

        return true;
    }

    /**
     * 获取台桌运行状态
     * @param int $tableId 台桌ID
     * @return int|null
     */
    public static function getTableRunStatus($tableId)
    {
        $tableInfo = self::getTableInfo($tableId);
        
        if (!$tableInfo) {
            return null;
        }

        return (int)($tableInfo['run_status'] ?? self::RUN_STATUS_WAITING);
    }

    /**
     * 获取台桌投注限额
     * @param int $tableId 台桌ID
     * @return array
     */
    public static function getTableLimits($tableId)
    {
        $tableInfo = self::getTableInfo($tableId);
        
        if (!$tableInfo) {
            return [
                'min_bet' => 10,
                'max_bet' => 50000
            ];
        }

        return [
            'min_bet' => (int)($tableInfo['min_bet'] ?? 10),
            'max_bet' => (int)($tableInfo['max_bet'] ?? 50000)
        ];
    }

    /**
     * 获取台桌游戏配置
     * @param int $tableId 台桌ID
     * @return array
     */
    public static function getTableGameConfig($tableId)
    {
        $tableInfo = self::getTableInfo($tableId);
        
        if (!$tableInfo) {
            return self::getDefaultGameConfig();
        }

        $gameConfig = $tableInfo['game_config'] ?? [];
        
        // 合并默认配置
        return array_merge(self::getDefaultGameConfig(), $gameConfig);
    }

    /**
     * 获取台桌历史记录
     * @param int $tableId 台桌ID
     * @param int $limit 数量限制
     * @return array
     */
    public static function getTableHistory($tableId, $limit = 20)
    {
        if ($tableId <= 0 || $limit <= 0) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . "history_{$tableId}_{$limit}";
        
        try {
            // 先从缓存获取
            $history = Cache::get($cacheKey);
            
            if ($history === null) {
                // 从数据库获取
                $history = Db::name('sicbo_game_results')
                    ->where('table_id', $tableId)
                    ->where('status', 1)
                    ->field('game_number,round_number,dice1,dice2,dice3,total_points,is_big,is_odd,has_triple,created_at')
                    ->order('id desc')
                    ->limit($limit)
                    ->select();

                $history = $history ? $history->toArray() : [];
                
                // 格式化历史数据
                foreach ($history as &$record) {
                    $record = self::formatGameResult($record);
                }
                
                // 缓存历史数据（较短时间）
                Cache::set($cacheKey, $history, 60);
            }
            
            return $history;
            
        } catch (\Exception $e) {
            Log::error('获取台桌历史失败', [
                'table_id' => $tableId,
                'limit' => $limit,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 获取台桌今日统计
     * @param int $tableId 台桌ID
     * @return array
     */
    public static function getTodayStats($tableId)
    {
        if ($tableId <= 0) {
            return self::getEmptyStats();
        }

        $cacheKey = self::CACHE_PREFIX . "today_stats_{$tableId}";
        
        try {
            // 先从缓存获取
            $stats = Cache::get($cacheKey);
            
            if ($stats === null) {
                $today = date('Y-m-d');
                $todayStart = strtotime($today . ' 00:00:00');
                $todayEnd = strtotime($today . ' 23:59:59');

                // 从数据库获取今日统计
                $rawStats = Db::name('sicbo_game_results')
                    ->where('table_id', $tableId)
                    ->where('status', 1)
                    ->where('created_at', '>=', $todayStart)
                    ->where('created_at', '<=', $todayEnd)
                    ->field([
                        'count(*) as total_rounds',
                        'sum(is_big) as big_count',
                        'sum(case when is_big = 0 then 1 else 0 end) as small_count',
                        'sum(case when total_points % 2 = 1 then 1 else 0 end) as odd_count',
                        'sum(case when total_points % 2 = 0 then 1 else 0 end) as even_count',
                        'sum(has_triple) as triple_count'
                    ])
                    ->find();

                $stats = [
                    'total_rounds' => (int)($rawStats['total_rounds'] ?? 0),
                    'big_count' => (int)($rawStats['big_count'] ?? 0),
                    'small_count' => (int)($rawStats['small_count'] ?? 0),
                    'odd_count' => (int)($rawStats['odd_count'] ?? 0),
                    'even_count' => (int)($rawStats['even_count'] ?? 0),
                    'triple_count' => (int)($rawStats['triple_count'] ?? 0),
                    'date' => $today,
                    'update_time' => time()
                ];

                // 计算百分比
                if ($stats['total_rounds'] > 0) {
                    $stats['big_percentage'] = round(($stats['big_count'] / $stats['total_rounds']) * 100, 1);
                    $stats['small_percentage'] = round(($stats['small_count'] / $stats['total_rounds']) * 100, 1);
                    $stats['odd_percentage'] = round(($stats['odd_count'] / $stats['total_rounds']) * 100, 1);
                    $stats['even_percentage'] = round(($stats['even_count'] / $stats['total_rounds']) * 100, 1);
                } else {
                    $stats['big_percentage'] = 0;
                    $stats['small_percentage'] = 0;
                    $stats['odd_percentage'] = 0;
                    $stats['even_percentage'] = 0;
                }
                
                // 缓存统计数据（较短时间）
                Cache::set($cacheKey, $stats, 120);
            }
            
            return $stats;
            
        } catch (\Exception $e) {
            Log::error('获取台桌今日统计失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return self::getEmptyStats();
        }
    }

    /**
     * 批量获取台桌信息
     * @param array $tableIds 台桌ID数组
     * @return array
     */
    public static function batchGetTableInfo(array $tableIds)
    {
        $results = [];
        
        foreach ($tableIds as $tableId) {
            $results[$tableId] = self::getTableInfo($tableId);
        }
        
        return $results;
    }

    /**
     * 获取所有可用的台桌
     * @return array
     */
    public static function getAvailableTables()
    {
        return self::getTableList(['status' => self::STATUS_OPEN]);
    }

    /**
     * 清除台桌缓存
     * @param int $tableId 台桌ID
     */
    public static function clearTableCache($tableId)
    {
        try {
            $cacheKeys = [
                self::CACHE_PREFIX . "info_{$tableId}",
                self::CACHE_PREFIX . "today_stats_{$tableId}",
            ];

            foreach ($cacheKeys as $key) {
                Cache::delete($key);
            }

            // 清除历史缓存（模糊匹配）
            $historyPattern = self::CACHE_PREFIX . "history_{$tableId}_*";
            // 这里简化处理，实际项目中可能需要更复杂的缓存清理逻辑
            
            Log::info('台桌缓存已清除', ['table_id' => $tableId]);
            
        } catch (\Exception $e) {
            Log::error('清除台桌缓存失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 格式化台桌信息
     * @param array $table 原始台桌数据
     * @return array
     */
    private static function formatTableInfo(array $table)
    {
        // 解析游戏配置
        $gameConfig = [];
        if (!empty($table['game_config'])) {
            $gameConfig = is_string($table['game_config']) 
                ? json_decode($table['game_config'], true) 
                : $table['game_config'];
            
            if (!is_array($gameConfig)) {
                $gameConfig = [];
            }
        }

        return [
            'id' => (int)($table['id'] ?? 0),
            'table_title' => $table['table_title'] ?? "台桌{$table['id']}",
            'game_type' => (int)($table['game_type'] ?? self::SICBO_GAME_TYPE),
            'status' => (int)($table['status'] ?? self::STATUS_CLOSED),
            'run_status' => (int)($table['run_status'] ?? self::RUN_STATUS_WAITING),
            'min_bet' => (int)($table['min_bet'] ?? 10),
            'max_bet' => (int)($table['max_bet'] ?? 50000),
            'game_config' => $gameConfig,
            'created_time' => $table['created_time'] ?? '',
            'status_text' => self::getStatusText((int)($table['status'] ?? 0)),
            'run_status_text' => self::getRunStatusText((int)($table['run_status'] ?? 0))
        ];
    }

    /**
     * 格式化游戏结果
     * @param array $result 原始结果数据
     * @return array
     */
    private static function formatGameResult(array $result)
    {
        $dice1 = (int)($result['dice1'] ?? 1);
        $dice2 = (int)($result['dice2'] ?? 1);
        $dice3 = (int)($result['dice3'] ?? 1);
        $totalPoints = (int)($result['total_points'] ?? ($dice1 + $dice2 + $dice3));

        return [
            'game_number' => $result['game_number'] ?? '',
            'round_number' => (int)($result['round_number'] ?? 0),
            'dice1' => $dice1,
            'dice2' => $dice2,
            'dice3' => $dice3,
            'total_points' => $totalPoints,
            'is_big' => (bool)($result['is_big'] ?? ($totalPoints >= 11)),
            'is_odd' => (bool)($result['is_odd'] ?? ($totalPoints % 2 === 1)),
            'has_triple' => (bool)($result['has_triple'] ?? ($dice1 === $dice2 && $dice2 === $dice3)),
            'created_at' => $result['created_at'] ?? '',
            'result_text' => "{$dice1}-{$dice2}-{$dice3}"
        ];
    }

    /**
     * 获取状态文本
     * @param int $status
     * @return string
     */
    private static function getStatusText($status)
    {
        $statusMap = [
            self::STATUS_CLOSED => '关闭',
            self::STATUS_OPEN => '开放',
            self::STATUS_MAINTENANCE => '维护'
        ];

        return $statusMap[$status] ?? '未知';
    }

    /**
     * 获取运行状态文本
     * @param int $runStatus
     * @return string
     */
    private static function getRunStatusText($runStatus)
    {
        $runStatusMap = [
            self::RUN_STATUS_WAITING => '等待中',
            self::RUN_STATUS_BETTING => '投注中',
            self::RUN_STATUS_DEALING => '开奖中'
        ];

        return $runStatusMap[$runStatus] ?? '未知';
    }

    /**
     * 获取默认游戏配置
     * @return array
     */
    private static function getDefaultGameConfig()
    {
        return [
            'betting_time' => 30,           // 投注时长
            'dice_rolling_time' => 10,      // 摇骰时长
            'result_display_time' => 5,     // 结果展示时长
            'limits' => [
                'min_bet_basic' => 10,      // 基础投注最小额
                'max_bet_basic' => 50000,   // 基础投注最大额
                'min_bet_total' => 10,      // 点数投注最小额
                'max_bet_total' => 10000,   // 点数投注最大额
            ],
            'auto_start' => false,          // 是否自动开始
            'max_players' => 100            // 最大玩家数
        ];
    }

    /**
     * 获取空统计数据
     * @return array
     */
    private static function getEmptyStats()
    {
        return [
            'total_rounds' => 0,
            'big_count' => 0,
            'small_count' => 0,
            'odd_count' => 0,
            'even_count' => 0,
            'triple_count' => 0,
            'big_percentage' => 0,
            'small_percentage' => 0,
            'odd_percentage' => 0,
            'even_percentage' => 0,
            'date' => date('Y-m-d'),
            'update_time' => time()
        ];
    }

    /**
     * 检查台桌是否存在
     * @param int $tableId 台桌ID
     * @return bool
     */
    public static function tableExists($tableId)
    {
        return self::getTableInfo($tableId) !== null;
    }

    /**
     * 获取台桌状态
     * @param int $tableId 台桌ID
     * @return int|null
     */
    public static function getTableStatus($tableId)
    {
        $tableInfo = self::getTableInfo($tableId);
        
        if (!$tableInfo) {
            return null;
        }

        return (int)($tableInfo['status'] ?? self::STATUS_CLOSED);
    }

    /**
     * 获取管理统计信息
     * @return array
     */
    public static function getManagerStats()
    {
        try {
            // 获取台桌总数统计
            $totalStats = Db::name('dianji_table')
                ->where('game_type', self::SICBO_GAME_TYPE)
                ->field([
                    'count(*) as total_tables',
                    'sum(case when status = 1 then 1 else 0 end) as open_tables',
                    'sum(case when run_status = 1 then 1 else 0 end) as betting_tables',
                    'sum(case when run_status = 2 then 1 else 0 end) as dealing_tables'
                ])
                ->find();

            return [
                'total_tables' => (int)($totalStats['total_tables'] ?? 0),
                'open_tables' => (int)($totalStats['open_tables'] ?? 0),
                'betting_tables' => (int)($totalStats['betting_tables'] ?? 0),
                'dealing_tables' => (int)($totalStats['dealing_tables'] ?? 0),
                'closed_tables' => ((int)($totalStats['total_tables'] ?? 0)) - ((int)($totalStats['open_tables'] ?? 0)),
                'cache_stats' => [
                    'memory_usage' => memory_get_usage(true),
                    'cache_count' => self::getCacheCount()
                ],
                'update_time' => time()
            ];

        } catch (\Exception $e) {
            Log::error('获取管理统计失败', ['error' => $e->getMessage()]);
            
            return [
                'total_tables' => 0,
                'open_tables' => 0,
                'betting_tables' => 0,
                'dealing_tables' => 0,
                'closed_tables' => 0,
                'cache_stats' => [
                    'memory_usage' => memory_get_usage(true),
                    'cache_count' => 0
                ],
                'update_time' => time(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取缓存数量（估算）
     * @return int
     */
    private static function getCacheCount()
    {
        // 这里简化处理，实际项目中可能需要更精确的缓存统计
        return 0;
    }
}