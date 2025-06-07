<?php

namespace app\websocket;

use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * 台桌管理器
 * 负责台桌信息管理、游戏状态管理等
 */
class TableManager
{
    /**
     * 台桌缓存前缀
     */
    private const TABLE_CACHE_PREFIX = 'table_';
    
    /**
     * 游戏状态缓存前缀
     */
    private const GAME_CACHE_PREFIX = 'game_';
    
    /**
     * 缓存有效期
     */
    private const CACHE_EXPIRE = 300; // 5分钟

    /**
     * 游戏状态常量
     */
    const GAME_STATUS_WAITING = 'waiting';     // 等待中
    const GAME_STATUS_BETTING = 'betting';     // 投注中
    const GAME_STATUS_DEALING = 'dealing';     // 开奖中
    const GAME_STATUS_SETTLING = 'settling';   // 结算中

    /**
     * 获取台桌信息
     * @param int $tableId
     * @return array|null
     */
    public static function getTableInfo($tableId)
    {
        try {
            $cacheKey = self::TABLE_CACHE_PREFIX . "info_{$tableId}";
            $tableInfo = Cache::get($cacheKey);
            
            if (!$tableInfo) {
                $table = Db::table('dianji_table')
                    ->where('id', $tableId)
                    ->find();
                    
                if ($table) {
                    $tableInfo = [
                        'id' => $table['id'],
                        'table_title' => $table['table_title'],
                        'game_type' => $table['game_type'],
                        'status' => $table['status'],
                        'min_bet' => $table['min_bet'] ?? 10,
                        'max_bet' => $table['max_bet'] ?? 50000,
                        'created_at' => $table['created_at']
                    ];
                    
                    // 缓存台桌信息
                    Cache::set($cacheKey, $tableInfo, self::CACHE_EXPIRE);
                }
            }
            
            return $tableInfo;
            
        } catch (\Exception $e) {
            Log::error('获取台桌信息失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取游戏状态
     * @param int $tableId
     * @return array
     */
    public static function getGameStatus($tableId)
    {
        try {
            $cacheKey = self::GAME_CACHE_PREFIX . "status_{$tableId}";
            $gameStatus = Cache::get($cacheKey);
            
            if (!$gameStatus) {
                $gameStatus = self::buildGameStatus($tableId);
                
                // 缓存游戏状态，较短时间
                Cache::set($cacheKey, $gameStatus, 60);
            }
            
            return $gameStatus;
            
        } catch (\Exception $e) {
            Log::error('获取游戏状态失败: ' . $e->getMessage());
            return self::getDefaultGameStatus($tableId);
        }
    }

    /**
     * 构建游戏状态
     * @param int $tableId
     * @return array
     */
    private static function buildGameStatus($tableId)
    {
        try {
            // 获取最新的游戏记录
            $latestGame = Db::table('sicbo_game_results')
                ->where('table_id', $tableId)
                ->order('created_at desc')
                ->find();

            // 获取当前进行中的游戏
            $currentGame = self::getCurrentGame($tableId);
            
            $gameStatus = [
                'table_id' => $tableId,
                'status' => self::GAME_STATUS_WAITING,
                'countdown' => 0,
                'current_game' => $currentGame,
                'last_result' => $latestGame,
                'update_time' => time()
            ];

            // 如果有当前游戏，更新状态
            if ($currentGame) {
                $gameStatus['status'] = $currentGame['status'];
                $gameStatus['countdown'] = $currentGame['countdown'];
            }
            
            return $gameStatus;
            
        } catch (\Exception $e) {
            Log::error('构建游戏状态失败: ' . $e->getMessage());
            return self::getDefaultGameStatus($tableId);
        }
    }

    /**
     * 获取当前进行中的游戏
     * @param int $tableId
     * @return array|null
     */
    private static function getCurrentGame($tableId)
    {
        try {
            // 这里可以从游戏控制表或缓存中获取当前游戏信息
            // 简化实现：检查是否有未结算的游戏
            $currentGame = Cache::get("current_game_{$tableId}");
            
            if (!$currentGame) {
                // 可以从数据库查询当前游戏状态
                // 这里提供一个默认的游戏结构
                $currentGame = null;
            }
            
            return $currentGame;
            
        } catch (\Exception $e) {
            Log::error('获取当前游戏失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取默认游戏状态
     * @param int $tableId
     * @return array
     */
    private static function getDefaultGameStatus($tableId)
    {
        return [
            'table_id' => $tableId,
            'status' => self::GAME_STATUS_WAITING,
            'countdown' => 0,
            'current_game' => null,
            'last_result' => null,
            'update_time' => time()
        ];
    }

    /**
     * 开始新游戏
     * @param int $tableId
     * @param array $gameConfig
     * @return array|null
     */
    public static function startNewGame($tableId, array $gameConfig = [])
    {
        try {
            $gameNumber = self::generateGameNumber($tableId);
            $bettingTime = $gameConfig['betting_time'] ?? 30;
            
            $newGame = [
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'status' => self::GAME_STATUS_BETTING,
                'countdown' => $bettingTime,
                'betting_start_time' => time(),
                'betting_end_time' => time() + $bettingTime,
                'round_number' => self::getNextRoundNumber($tableId)
            ];
            
            // 缓存当前游戏
            Cache::set("current_game_{$tableId}", $newGame, $bettingTime + 300);
            
            // 更新游戏状态缓存
            self::updateGameStatusCache($tableId, $newGame);
            
            Log::info('新游戏开始', [
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'betting_time' => $bettingTime
            ]);
            
            return $newGame;
            
        } catch (\Exception $e) {
            Log::error('开始新游戏失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 停止投注
     * @param int $tableId
     * @return bool
     */
    public static function stopBetting($tableId)
    {
        try {
            $currentGame = Cache::get("current_game_{$tableId}");
            
            if (!$currentGame) {
                return false;
            }
            
            // 更新游戏状态为开奖中
            $currentGame['status'] = self::GAME_STATUS_DEALING;
            $currentGame['countdown'] = 10; // 开奖倒计时
            $currentGame['betting_end_time'] = time();
            
            // 更新缓存
            Cache::set("current_game_{$tableId}", $currentGame, 600);
            self::updateGameStatusCache($tableId, $currentGame);
            
            Log::info('停止投注', [
                'table_id' => $tableId,
                'game_number' => $currentGame['game_number']
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('停止投注失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 公布游戏结果
     * @param int $tableId
     * @param array $result
     * @return bool
     */
    public static function announceResult($tableId, array $result)
    {
        try {
            $currentGame = Cache::get("current_game_{$tableId}");
            
            if (!$currentGame) {
                return false;
            }
            
            // 保存游戏结果到数据库
            $gameResultId = self::saveGameResult($tableId, $currentGame['game_number'], $result);
            
            if (!$gameResultId) {
                return false;
            }
            
            // 更新游戏状态为结算中
            $currentGame['status'] = self::GAME_STATUS_SETTLING;
            $currentGame['countdown'] = 5;
            $currentGame['result'] = $result;
            $currentGame['result_time'] = time();
            
            // 更新缓存
            Cache::set("current_game_{$tableId}", $currentGame, 300);
            self::updateGameStatusCache($tableId, $currentGame);
            
            Log::info('公布游戏结果', [
                'table_id' => $tableId,
                'game_number' => $currentGame['game_number'],
                'result' => $result
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('公布游戏结果失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 结束游戏
     * @param int $tableId
     * @return bool
     */
    public static function endGame($tableId)
    {
        try {
            // 清除当前游戏缓存
            Cache::delete("current_game_{$tableId}");
            
            // 重置游戏状态为等待中
            $gameStatus = self::getDefaultGameStatus($tableId);
            Cache::set(self::GAME_CACHE_PREFIX . "status_{$tableId}", $gameStatus, 60);
            
            Log::info('游戏结束', ['table_id' => $tableId]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('结束游戏失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取台桌统计信息
     * @param int $tableId
     * @return array
     */
    public static function getTableStats($tableId)
    {
        try {
            $cacheKey = self::TABLE_CACHE_PREFIX . "stats_{$tableId}";
            $stats = Cache::get($cacheKey);
            
            if (!$stats) {
                $stats = self::calculateTableStats($tableId);
                
                // 缓存统计信息
                Cache::set($cacheKey, $stats, self::CACHE_EXPIRE);
            }
            
            return $stats;
            
        } catch (\Exception $e) {
            Log::error('获取台桌统计失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 计算台桌统计
     * @param int $tableId
     * @return array
     */
    private static function calculateTableStats($tableId)
    {
        try {
            $today = date('Y-m-d');
            
            // 今日游戏统计
            $todayStats = Db::table('sicbo_game_results')
                ->where('table_id', $tableId)
                ->where('created_at', '>=', $today . ' 00:00:00')
                ->where('created_at', '<=', $today . ' 23:59:59')
                ->where('status', 1)
                ->field([
                    'COUNT(*) as total_rounds',
                    'SUM(CASE WHEN is_big = 1 THEN 1 ELSE 0 END) as big_count',
                    'SUM(CASE WHEN is_big = 0 THEN 1 ELSE 0 END) as small_count',
                    'SUM(CASE WHEN is_odd = 1 THEN 1 ELSE 0 END) as odd_count',
                    'SUM(CASE WHEN is_odd = 0 THEN 1 ELSE 0 END) as even_count',
                    'SUM(CASE WHEN has_triple = 1 THEN 1 ELSE 0 END) as triple_count'
                ])
                ->find();

            // 最近游戏结果
            $recentResults = Db::table('sicbo_game_results')
                ->where('table_id', $tableId)
                ->where('status', 1)
                ->field('dice1,dice2,dice3,total_points,is_big,is_odd,has_triple,created_at')
                ->order('created_at desc')
                ->limit(20)
                ->select();

            return [
                'today' => [
                    'total_rounds' => (int)($todayStats['total_rounds'] ?? 0),
                    'big_count' => (int)($todayStats['big_count'] ?? 0),
                    'small_count' => (int)($todayStats['small_count'] ?? 0),
                    'odd_count' => (int)($todayStats['odd_count'] ?? 0),
                    'even_count' => (int)($todayStats['even_count'] ?? 0),
                    'triple_count' => (int)($todayStats['triple_count'] ?? 0)
                ],
                'recent_results' => $recentResults ? $recentResults->toArray() : [],
                'update_time' => time()
            ];
            
        } catch (\Exception $e) {
            Log::error('计算台桌统计失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取台桌列表
     * @param array $conditions
     * @return array
     */
    public static function getTableList(array $conditions = [])
    {
        try {
            $query = Db::table('dianji_table')
                ->where('game_type', 9); // 骰宝游戏类型
                
            // 添加条件
            if (isset($conditions['status'])) {
                $query->where('status', $conditions['status']);
            }
            
            $tables = $query->field('id,table_title,status,min_bet,max_bet,created_at')
                ->order('id asc')
                ->select();
                
            return $tables ? $tables->toArray() : [];
            
        } catch (\Exception $e) {
            Log::error('获取台桌列表失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 更新台桌状态
     * @param int $tableId
     * @param int $status
     * @return bool
     */
    public static function updateTableStatus($tableId, $status)
    {
        try {
            $result = Db::table('dianji_table')
                ->where('id', $tableId)
                ->update([
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
            if ($result) {
                // 清除台桌缓存
                self::clearTableCache($tableId);
                
                Log::info('台桌状态更新', [
                    'table_id' => $tableId,
                    'status' => $status
                ]);
            }
            
            return $result > 0;
            
        } catch (\Exception $e) {
            Log::error('更新台桌状态失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 生成游戏局号
     * @param int $tableId
     * @return string
     */
    private static function generateGameNumber($tableId)
    {
        $date = date('Ymd');
        $time = date('His');
        $random = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        return "T{$tableId}_{$date}_{$time}_{$random}";
    }

    /**
     * 获取下一轮次号
     * @param int $tableId
     * @return int
     */
    private static function getNextRoundNumber($tableId)
    {
        try {
            $today = date('Y-m-d');
            
            $lastRound = Db::table('sicbo_game_results')
                ->where('table_id', $tableId)
                ->where('created_at', '>=', $today . ' 00:00:00')
                ->where('created_at', '<=', $today . ' 23:59:59')
                ->max('round_number');
                
            return ((int)$lastRound) + 1;
            
        } catch (\Exception $e) {
            Log::error('获取下一轮次号失败: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * 保存游戏结果
     * @param int $tableId
     * @param string $gameNumber
     * @param array $result
     * @return int|null
     */
    private static function saveGameResult($tableId, $gameNumber, array $result)
    {
        try {
            $totalPoints = $result['dice1'] + $result['dice2'] + $result['dice3'];
            $isBig = $totalPoints >= 11;
            $isOdd = $totalPoints % 2 == 1;
            $hasTriple = ($result['dice1'] == $result['dice2']) && ($result['dice2'] == $result['dice3']);
            $hasPair = !$hasTriple && (
                ($result['dice1'] == $result['dice2']) || 
                ($result['dice2'] == $result['dice3']) || 
                ($result['dice1'] == $result['dice3'])
            );

            $gameResultData = [
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'round_number' => self::getNextRoundNumber($tableId),
                'dice1' => $result['dice1'],
                'dice2' => $result['dice2'],
                'dice3' => $result['dice3'],
                'total_points' => $totalPoints,
                'is_big' => $isBig ? 1 : 0,
                'is_odd' => $isOdd ? 1 : 0,
                'has_triple' => $hasTriple ? 1 : 0,
                'triple_number' => $hasTriple ? $result['dice1'] : null,
                'has_pair' => $hasPair ? 1 : 0,
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $gameResultId = Db::table('sicbo_game_results')->insertGetId($gameResultData);
            
            // 清除统计缓存
            self::clearTableStatsCache($tableId);
            
            return $gameResultId;
            
        } catch (\Exception $e) {
            Log::error('保存游戏结果失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 更新游戏状态缓存
     * @param int $tableId
     * @param array $gameData
     */
    private static function updateGameStatusCache($tableId, array $gameData)
    {
        try {
            $gameStatus = [
                'table_id' => $tableId,
                'status' => $gameData['status'],
                'countdown' => $gameData['countdown'],
                'current_game' => $gameData,
                'update_time' => time()
            ];
            
            Cache::set(self::GAME_CACHE_PREFIX . "status_{$tableId}", $gameStatus, 60);
            
        } catch (\Exception $e) {
            Log::error('更新游戏状态缓存失败: ' . $e->getMessage());
        }
    }

    /**
     * 清除台桌缓存
     * @param int $tableId
     */
    public static function clearTableCache($tableId)
    {
        try {
            $cacheKeys = [
                self::TABLE_CACHE_PREFIX . "info_{$tableId}",
                self::TABLE_CACHE_PREFIX . "stats_{$tableId}",
                self::GAME_CACHE_PREFIX . "status_{$tableId}",
                "current_game_{$tableId}"
            ];

            foreach ($cacheKeys as $key) {
                Cache::delete($key);
            }
            
        } catch (\Exception $e) {
            Log::error('清除台桌缓存失败: ' . $e->getMessage());
        }
    }

    /**
     * 清除台桌统计缓存
     * @param int $tableId
     */
    private static function clearTableStatsCache($tableId)
    {
        try {
            Cache::delete(self::TABLE_CACHE_PREFIX . "stats_{$tableId}");
            
        } catch (\Exception $e) {
            Log::error('清除台桌统计缓存失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取台桌游戏历史
     * @param int $tableId
     * @param int $limit
     * @return array
     */
    public static function getTableHistory($tableId, $limit = 20)
    {
        try {
            $history = Db::table('sicbo_game_results')
                ->where('table_id', $tableId)
                ->where('status', 1)
                ->field('game_number,round_number,dice1,dice2,dice3,total_points,is_big,is_odd,has_triple,created_at')
                ->order('created_at desc')
                ->limit($limit)
                ->select();
                
            return $history ? $history->toArray() : [];
            
        } catch (\Exception $e) {
            Log::error('获取台桌历史失败: ' . $e->getMessage());
            return [];
        }
    }
}