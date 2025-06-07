<?php



namespace app\service\sicbo;

use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboBetRecords;
use app\model\sicbo\SicboStatistics;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * 骰宝统计分析服务类
 * 负责游戏数据统计、趋势分析、热度统计等功能
 */
class SicboStatisticsService
{
    /**
     * 缓存键名前缀
     */
    private const CACHE_PREFIX = 'sicbo_stats_';
    
    /**
     * 缓存时间(秒)
     */
    private const CACHE_TIME = 300; // 5分钟
    
    /**
     * 统计类型常量
     */
    private const STAT_HOURLY = 'hourly';
    private const STAT_DAILY = 'daily';
    private const STAT_WEEKLY = 'weekly';
    private const STAT_MONTHLY = 'monthly';

    /**
     * 获取台桌实时统计数据
     * 
     * @param int $tableId 台桌ID
     * @param int $limit 历史数据条数
     * @return array
     */
    public function getRealtimeStats(int $tableId, int $limit = 50): array
    {
        $cacheKey = self::CACHE_PREFIX . "realtime_{$tableId}_{$limit}";
        
        return Cache::remember($cacheKey, function () use ($tableId, $limit) {
            // 获取最近的游戏结果
            $recentResults = SicboGameResults::where('table_id', $tableId)
                ->where('status', 1)
                ->order('created_at desc')
                ->limit($limit)
                ->select()
                ->toArray();

            if (empty($recentResults)) {
                return $this->getEmptyRealtimeStats();
            }

            // 计算基础统计
            $basicStats = $this->calculateBasicStats($recentResults);
            
            // 计算趋势数据
            $trendData = $this->calculateTrendData($recentResults);
            
            // 计算热冷号码
            $hotColdNumbers = $this->calculateHotColdNumbers($recentResults);
            
            // 计算连续统计
            $streakStats = $this->calculateStreakStats($recentResults);

            return [
                'table_id' => $tableId,
                'total_games' => count($recentResults),
                'basic_stats' => $basicStats,
                'trend_data' => $trendData,
                'hot_cold_numbers' => $hotColdNumbers,
                'streak_stats' => $streakStats,
                'last_results' => array_slice($recentResults, 0, 10), // 最近10局
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }, self::CACHE_TIME);
    }

    /**
     * 获取台桌历史统计数据
     * 
     * @param int $tableId 台桌ID
     * @param string $period 统计周期 (today/week/month)
     * @return array
     */
    public function getHistoricalStats(int $tableId, string $period = 'today'): array
    {
        $cacheKey = self::CACHE_PREFIX . "historical_{$tableId}_{$period}";
        
        return Cache::remember($cacheKey, function () use ($tableId, $period) {
            $dateRange = $this->getDateRange($period);
            
            // 从统计表获取数据
            $stats = SicboStatistics::where('table_id', $tableId)
                ->where('stat_date', '>=', $dateRange['start'])
                ->where('stat_date', '<=', $dateRange['end'])
                ->order('stat_date desc')
                ->select()
                ->toArray();

            if (empty($stats)) {
                // 如果统计表没有数据，从原始数据计算
                return $this->calculateHistoricalFromRawData($tableId, $dateRange);
            }

            return $this->formatHistoricalStats($stats, $period);
        }, self::CACHE_TIME);
    }

    /**
     * 获取投注统计数据
     * 
     * @param int $tableId 台桌ID
     * @param string $period 统计周期
     * @return array
     */
    public function getBettingStats(int $tableId, string $period = 'today'): array
    {
        $cacheKey = self::CACHE_PREFIX . "betting_{$tableId}_{$period}";
        
        return Cache::remember($cacheKey, function () use ($tableId, $period) {
            $dateRange = $this->getDateRange($period);
            
            // 获取投注统计
            $bettingStats = Db::table('ntp_sicbo_bet_records')
                ->alias('br')
                ->join('ntp_sicbo_game_results gr', 'br.game_number = gr.game_number')
                ->where('br.table_id', $tableId)
                ->where('br.bet_time', '>=', $dateRange['start'])
                ->where('br.bet_time', '<=', $dateRange['end'])
                ->field([
                    'br.bet_type',
                    'COUNT(*) as bet_count',
                    'SUM(br.bet_amount) as total_bet_amount',
                    'SUM(br.win_amount) as total_win_amount',
                    'COUNT(CASE WHEN br.is_win = 1 THEN 1 END) as win_count',
                    'AVG(br.bet_amount) as avg_bet_amount'
                ])
                ->group('br.bet_type')
                ->select();

            // 计算总计数据
            $totalStats = [
                'total_bets' => 0,
                'total_bet_amount' => 0,
                'total_win_amount' => 0,
                'total_players' => 0,
                'house_edge' => 0
            ];

            $betTypeStats = [];
            foreach ($bettingStats as $stat) {
                $betTypeStats[$stat['bet_type']] = [
                    'bet_count' => (int)$stat['bet_count'],
                    'total_bet_amount' => (float)$stat['total_bet_amount'],
                    'total_win_amount' => (float)$stat['total_win_amount'],
                    'win_count' => (int)$stat['win_count'],
                    'win_rate' => $stat['bet_count'] > 0 ? round($stat['win_count'] / $stat['bet_count'] * 100, 2) : 0,
                    'avg_bet_amount' => (float)$stat['avg_bet_amount'],
                    'profit_loss' => (float)$stat['total_bet_amount'] - (float)$stat['total_win_amount']
                ];

                $totalStats['total_bets'] += $stat['bet_count'];
                $totalStats['total_bet_amount'] += $stat['total_bet_amount'];
                $totalStats['total_win_amount'] += $stat['total_win_amount'];
            }

            // 计算总庄家优势
            if ($totalStats['total_bet_amount'] > 0) {
                $totalStats['house_edge'] = round(
                    ($totalStats['total_bet_amount'] - $totalStats['total_win_amount']) / 
                    $totalStats['total_bet_amount'] * 100, 
                    2
                );
            }

            // 获取参与玩家数
            $totalStats['total_players'] = Db::table('ntp_sicbo_bet_records')
                ->where('table_id', $tableId)
                ->where('bet_time', '>=', $dateRange['start'])
                ->where('bet_time', '<=', $dateRange['end'])
                ->group('user_id')
                ->count();

            return [
                'period' => $period,
                'date_range' => $dateRange,
                'total_stats' => $totalStats,
                'bet_type_stats' => $betTypeStats
            ];
        }, self::CACHE_TIME);
    }

    /**
     * 获取用户行为分析
     * 
     * @param int $tableId 台桌ID (可选)
     * @param string $period 统计周期
     * @return array
     */
    public function getUserBehaviorAnalysis(?int $tableId = null, string $period = 'today'): array
    {
        $cacheKey = self::CACHE_PREFIX . "user_behavior_{$tableId}_{$period}";
        
        return Cache::remember($cacheKey, function () use ($tableId, $period) {
            $dateRange = $this->getDateRange($period);
            
            $query = Db::table('ntp_sicbo_bet_records')
                ->where('bet_time', '>=', $dateRange['start'])
                ->where('bet_time', '<=', $dateRange['end']);
                
            if ($tableId) {
                $query->where('table_id', $tableId);
            }

            // 用户投注偏好分析
            $userPreferences = $query->field([
                'user_id',
                'bet_type',
                'COUNT(*) as frequency',
                'SUM(bet_amount) as total_amount',
                'AVG(bet_amount) as avg_amount'
            ])
            ->group('user_id, bet_type')
            ->select();

            // 用户活跃度分析
            $userActivity = $query->field([
                'user_id',
                'COUNT(DISTINCT DATE(bet_time)) as active_days',
                'COUNT(*) as total_bets',
                'SUM(bet_amount) as total_bet_amount',
                'SUM(win_amount) as total_win_amount',
                'MIN(bet_time) as first_bet_time',
                'MAX(bet_time) as last_bet_time'
            ])
            ->group('user_id')
            ->select();

            // 分析用户类型
            $userTypes = $this->categorizeUsers($userActivity);
            
            return [
                'period' => $period,
                'user_preferences' => $this->analyzeUserPreferences($userPreferences),
                'user_activity' => $this->analyzeUserActivity($userActivity),
                'user_types' => $userTypes,
                'summary' => $this->generateUserBehaviorSummary($userActivity, $userTypes)
            ];
        }, self::CACHE_TIME * 2); // 用户行为分析缓存时间更长
    }

    /**
     * 更新统计数据到数据库
     * 
     * @param int $tableId 台桌ID
     * @param string $statType 统计类型
     * @param string $statDate 统计日期
     * @return bool
     */
    public function updateStatistics(int $tableId, string $statType = self::STAT_DAILY, ?string $statDate = null): bool
    {
        try {
            if (!$statDate) {
                $statDate = date('Y-m-d');
            }

            $dateRange = $this->getDateRangeForStatType($statType, $statDate);
            
            // 获取游戏结果统计
            $gameStats = $this->calculateGameStatsForPeriod($tableId, $dateRange);
            
            // 获取投注统计
            $betStats = $this->calculateBetStatsForPeriod($tableId, $dateRange);
            
            // 合并统计数据
            $statisticsData = array_merge($gameStats, $betStats, [
                'table_id' => $tableId,
                'stat_date' => $statDate,
                'stat_type' => $statType,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 保存或更新统计数据
            $existing = SicboStatistics::where([
                'table_id' => $tableId,
                'stat_date' => $statDate,
                'stat_type' => $statType
            ])->find();

            if ($existing) {
                $existing->save($statisticsData);
            } else {
                SicboStatistics::create($statisticsData);
            }

            // 清除相关缓存
            $this->clearRelatedCache($tableId);
            
            Log::info("骰宝统计数据更新成功", [
                'table_id' => $tableId,
                'stat_type' => $statType,
                'stat_date' => $statDate
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("骰宝统计数据更新失败: " . $e->getMessage(), [
                'table_id' => $tableId,
                'stat_type' => $statType,
                'stat_date' => $statDate
            ]);
            return false;
        }
    }

    /**
     * 计算基础统计数据
     * 
     * @param array $results 游戏结果数组
     * @return array
     */
    private function calculateBasicStats(array $results): array
    {
        $totalGames = count($results);
        $bigCount = 0;
        $smallCount = 0;
        $oddCount = 0;
        $evenCount = 0;
        $tripleCount = 0;
        $pairCount = 0;
        
        $totalDistribution = [];
        for ($i = 3; $i <= 18; $i++) {
            $totalDistribution[$i] = 0;
        }

        foreach ($results as $result) {
            // 大小统计
            if ($result['is_big'] == 1) {
                $bigCount++;
            } else {
                $smallCount++;
            }
            
            // 单双统计
            if ($result['is_odd'] == 1) {
                $oddCount++;
            } else {
                $evenCount++;
            }
            
            // 特殊统计
            if ($result['has_triple'] == 1) {
                $tripleCount++;
            }
            if ($result['has_pair'] == 1) {
                $pairCount++;
            }
            
            // 总点数分布
            $total = $result['total_points'];
            if ($total >= 3 && $total <= 18) {
                $totalDistribution[$total]++;
            }
        }

        return [
            'total_games' => $totalGames,
            'big_count' => $bigCount,
            'small_count' => $smallCount,
            'big_rate' => $totalGames > 0 ? round($bigCount / $totalGames * 100, 1) : 0,
            'small_rate' => $totalGames > 0 ? round($smallCount / $totalGames * 100, 1) : 0,
            'odd_count' => $oddCount,
            'even_count' => $evenCount,
            'odd_rate' => $totalGames > 0 ? round($oddCount / $totalGames * 100, 1) : 0,
            'even_rate' => $totalGames > 0 ? round($evenCount / $totalGames * 100, 1) : 0,
            'triple_count' => $tripleCount,
            'pair_count' => $pairCount,
            'triple_rate' => $totalGames > 0 ? round($tripleCount / $totalGames * 100, 2) : 0,
            'total_distribution' => $totalDistribution
        ];
    }

    /**
     * 计算趋势数据
     * 
     * @param array $results 游戏结果数组
     * @return array
     */
    private function calculateTrendData(array $results): array
    {
        $trendData = [
            'big_small_trend' => [],
            'odd_even_trend' => [],
            'total_trend' => []
        ];

        foreach ($results as $index => $result) {
            $trendData['big_small_trend'][] = [
                'game_number' => $result['game_number'],
                'value' => $result['is_big'] ? 'big' : 'small',
                'total_points' => $result['total_points']
            ];
            
            $trendData['odd_even_trend'][] = [
                'game_number' => $result['game_number'],
                'value' => $result['is_odd'] ? 'odd' : 'even',
                'total_points' => $result['total_points']
            ];
            
            $trendData['total_trend'][] = [
                'game_number' => $result['game_number'],
                'total_points' => $result['total_points'],
                'dice1' => $result['dice1'],
                'dice2' => $result['dice2'],
                'dice3' => $result['dice3']
            ];
        }

        return $trendData;
    }

    /**
     * 计算热冷号码
     * 
     * @param array $results 游戏结果数组
     * @return array
     */
    private function calculateHotColdNumbers(array $results): array
    {
        $diceFrequency = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
        $totalFrequency = [];
        
        for ($i = 3; $i <= 18; $i++) {
            $totalFrequency[$i] = 0;
        }

        foreach ($results as $result) {
            // 统计单骰频率
            $diceFrequency[$result['dice1']]++;
            $diceFrequency[$result['dice2']]++;
            $diceFrequency[$result['dice3']]++;
            
            // 统计总点数频率
            $totalFrequency[$result['total_points']]++;
        }

        // 排序得到热冷号码
        arsort($diceFrequency);
        $hotDice = array_slice(array_keys($diceFrequency), 0, 3, true);
        $coldDice = array_slice(array_keys($diceFrequency), -3, 3, true);

        arsort($totalFrequency);
        $hotTotals = array_slice(array_keys($totalFrequency), 0, 5, true);
        $coldTotals = array_slice(array_keys($totalFrequency), -5, 5, true);

        return [
            'dice_frequency' => $diceFrequency,
            'total_frequency' => $totalFrequency,
            'hot_dice' => array_map(function($dice) use ($diceFrequency) {
                return ['number' => $dice, 'frequency' => $diceFrequency[$dice]];
            }, $hotDice),
            'cold_dice' => array_map(function($dice) use ($diceFrequency) {
                return ['number' => $dice, 'frequency' => $diceFrequency[$dice]];
            }, array_reverse($coldDice)),
            'hot_totals' => array_map(function($total) use ($totalFrequency) {
                return ['total' => $total, 'frequency' => $totalFrequency[$total]];
            }, $hotTotals),
            'cold_totals' => array_map(function($total) use ($totalFrequency) {
                return ['total' => $total, 'frequency' => $totalFrequency[$total]];
            }, array_reverse($coldTotals))
        ];
    }

    /**
     * 计算连续统计
     * 
     * @param array $results 游戏结果数组
     * @return array
     */
    private function calculateStreakStats(array $results): array
    {
        $bigStreak = 0;
        $smallStreak = 0;
        $oddStreak = 0;
        $evenStreak = 0;
        
        $maxBigStreak = 0;
        $maxSmallStreak = 0;
        $maxOddStreak = 0;
        $maxEvenStreak = 0;

        foreach ($results as $result) {
            // 大小连续统计
            if ($result['is_big'] == 1) {
                $bigStreak++;
                $smallStreak = 0;
                $maxBigStreak = max($maxBigStreak, $bigStreak);
            } else {
                $smallStreak++;
                $bigStreak = 0;
                $maxSmallStreak = max($maxSmallStreak, $smallStreak);
            }
            
            // 单双连续统计
            if ($result['is_odd'] == 1) {
                $oddStreak++;
                $evenStreak = 0;
                $maxOddStreak = max($maxOddStreak, $oddStreak);
            } else {
                $evenStreak++;
                $oddStreak = 0;
                $maxEvenStreak = max($maxEvenStreak, $evenStreak);
            }
        }

        return [
            'current_streaks' => [
                'big_streak' => $bigStreak,
                'small_streak' => $smallStreak,
                'odd_streak' => $oddStreak,
                'even_streak' => $evenStreak
            ],
            'max_streaks' => [
                'max_big_streak' => $maxBigStreak,
                'max_small_streak' => $maxSmallStreak,
                'max_odd_streak' => $maxOddStreak,
                'max_even_streak' => $maxEvenStreak
            ]
        ];
    }

    /**
     * 获取空的实时统计数据
     * 
     * @return array
     */
    private function getEmptyRealtimeStats(): array
    {
        return [
            'total_games' => 0,
            'basic_stats' => [
                'total_games' => 0,
                'big_count' => 0,
                'small_count' => 0,
                'big_rate' => 0,
                'small_rate' => 0,
                'odd_count' => 0,
                'even_count' => 0,
                'odd_rate' => 0,
                'even_rate' => 0,
                'triple_count' => 0,
                'pair_count' => 0,
                'triple_rate' => 0,
                'total_distribution' => []
            ],
            'trend_data' => [
                'big_small_trend' => [],
                'odd_even_trend' => [],
                'total_trend' => []
            ],
            'hot_cold_numbers' => [
                'dice_frequency' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0],
                'total_frequency' => [],
                'hot_dice' => [],
                'cold_dice' => [],
                'hot_totals' => [],
                'cold_totals' => []
            ],
            'streak_stats' => [
                'current_streaks' => [
                    'big_streak' => 0,
                    'small_streak' => 0,
                    'odd_streak' => 0,
                    'even_streak' => 0
                ],
                'max_streaks' => [
                    'max_big_streak' => 0,
                    'max_small_streak' => 0,
                    'max_odd_streak' => 0,
                    'max_even_streak' => 0
                ]
            ],
            'last_results' => [],
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * 获取日期范围
     * 
     * @param string $period 周期类型
     * @return array
     */
    private function getDateRange(string $period): array
    {
        $now = new \DateTime();
        
        switch ($period) {
            case 'today':
                return [
                    'start' => $now->format('Y-m-d 00:00:00'),
                    'end' => $now->format('Y-m-d 23:59:59')
                ];
            case 'week':
                $start = clone $now;
                $start->modify('monday this week');
                return [
                    'start' => $start->format('Y-m-d 00:00:00'),
                    'end' => $now->format('Y-m-d 23:59:59')
                ];
            case 'month':
                return [
                    'start' => $now->format('Y-m-01 00:00:00'),
                    'end' => $now->format('Y-m-d 23:59:59')
                ];
            default:
                return [
                    'start' => $now->format('Y-m-d 00:00:00'),
                    'end' => $now->format('Y-m-d 23:59:59')
                ];
        }
    }

    /**
     * 从原始数据计算历史统计
     * 
     * @param int $tableId 台桌ID
     * @param array $dateRange 日期范围
     * @return array
     */
    private function calculateHistoricalFromRawData(int $tableId, array $dateRange): array
    {
        $results = SicboGameResults::where('table_id', $tableId)
            ->where('created_at', '>=', $dateRange['start'])
            ->where('created_at', '<=', $dateRange['end'])
            ->where('status', 1)
            ->order('created_at desc')
            ->select()
            ->toArray();

        if (empty($results)) {
            return ['message' => '指定时间段内没有数据'];
        }

        return [
            'basic_stats' => $this->calculateBasicStats($results),
            'trend_data' => $this->calculateTrendData($results),
            'hot_cold_numbers' => $this->calculateHotColdNumbers($results),
            'date_range' => $dateRange,
            'data_source' => 'raw_calculation'
        ];
    }

    /**
     * 格式化历史统计数据
     * 
     * @param array $stats 统计数据
     * @param string $period 周期
     * @return array
     */
    private function formatHistoricalStats(array $stats, string $period): array
    {
        // 处理统计表中的数据格式化
        $formattedStats = [];
        
        foreach ($stats as $stat) {
            $formattedStats[] = [
                'date' => $stat['stat_date'],
                'total_rounds' => $stat['total_rounds'],
                'big_count' => $stat['big_count'],
                'small_count' => $stat['small_count'],
                'odd_count' => $stat['odd_count'],
                'even_count' => $stat['even_count'],
                'triple_count' => $stat['triple_count'],
                'pair_count' => $stat['pair_count'],
                'total_bet_amount' => $stat['total_bet_amount'],
                'total_win_amount' => $stat['total_win_amount'],
                'player_count' => $stat['player_count'],
                'total_distribution' => json_decode($stat['total_distribution'] ?? '{}', true),
                'dice_distribution' => json_decode($stat['dice_distribution'] ?? '{}', true)
            ];
        }

        return [
            'period' => $period,
            'stats' => $formattedStats,
            'data_source' => 'statistics_table'
        ];
    }

    /**
     * 分析用户偏好
     * 
     * @param array $preferences 用户偏好数据
     * @return array
     */
    private function analyzeUserPreferences(array $preferences): array
    {
        $betTypePopularity = [];
        $userBetPatterns = [];

        foreach ($preferences as $pref) {
            $betType = $pref['bet_type'];
            
            if (!isset($betTypePopularity[$betType])) {
                $betTypePopularity[$betType] = [
                    'user_count' => 0,
                    'total_frequency' => 0,
                    'total_amount' => 0
                ];
            }
            
            $betTypePopularity[$betType]['user_count']++;
            $betTypePopularity[$betType]['total_frequency'] += $pref['frequency'];
            $betTypePopularity[$betType]['total_amount'] += $pref['total_amount'];
            
            if (!isset($userBetPatterns[$pref['user_id']])) {
                $userBetPatterns[$pref['user_id']] = [];
            }
            
            $userBetPatterns[$pref['user_id']][$betType] = [
                'frequency' => $pref['frequency'],
                'total_amount' => $pref['total_amount'],
                'avg_amount' => $pref['avg_amount']
            ];
        }

        // 计算投注类型受欢迎程度
        uasort($betTypePopularity, function($a, $b) {
            return $b['user_count'] - $a['user_count'];
        });

        return [
            'bet_type_popularity' => $betTypePopularity,
            'total_users_analyzed' => count($userBetPatterns)
        ];
    }

    /**
     * 分析用户活跃度
     * 
     * @param array $activity 用户活跃度数据
     * @return array
     */
    private function analyzeUserActivity(array $activity): array
    {
        $totalUsers = count($activity);
        $totalBets = 0;
        $totalBetAmount = 0;
        $totalWinAmount = 0;
        
        $activeDaysDistribution = [];
        $betFrequencyDistribution = [];

        foreach ($activity as $user) {
            $totalBets += $user['total_bets'];
            $totalBetAmount += $user['total_bet_amount'];
            $totalWinAmount += $user['total_win_amount'];
            
            // 活跃天数分布
            $activeDays = $user['active_days'];
            $activeDaysDistribution[$activeDays] = ($activeDaysDistribution[$activeDays] ?? 0) + 1;
            
            // 投注频次分布
            $betCount = $user['total_bets'];
            if ($betCount <= 10) $range = '1-10';
            elseif ($betCount <= 50) $range = '11-50';
            elseif ($betCount <= 100) $range = '51-100';
            else $range = '100+';
            
            $betFrequencyDistribution[$range] = ($betFrequencyDistribution[$range] ?? 0) + 1;
        }

        return [
            'total_users' => $totalUsers,
            'avg_bets_per_user' => $totalUsers > 0 ? round($totalBets / $totalUsers, 1) : 0,
            'avg_bet_amount_per_user' => $totalUsers > 0 ? round($totalBetAmount / $totalUsers, 2) : 0,
            'overall_win_rate' => $totalBetAmount > 0 ? round($totalWinAmount / $totalBetAmount * 100, 2) : 0,
            'active_days_distribution' => $activeDaysDistribution,
            'bet_frequency_distribution' => $betFrequencyDistribution
        ];
    }

    /**
     * 用户分类
     * 
     * @param array $activity 用户活跃度数据
     * @return array
     */
    private function categorizeUsers(array $activity): array
    {
        $userTypes = [
            'high_value' => 0,      // 高价值用户：投注额大
            'frequent' => 0,        // 高频用户：投注次数多
            'loyal' => 0,          // 忠诚用户：活跃天数多
            'new' => 0,            // 新用户：只有1天活跃
            'casual' => 0          // 休闲用户：其他
        ];

        foreach ($activity as $user) {
            $totalAmount = $user['total_bet_amount'];
            $totalBets = $user['total_bets'];
            $activeDays = $user['active_days'];
            
            if ($totalAmount >= 10000) {
                $userTypes['high_value']++;
            } elseif ($totalBets >= 100) {
                $userTypes['frequent']++;
            } elseif ($activeDays >= 7) {
                $userTypes['loyal']++;
            } elseif ($activeDays == 1) {
                $userTypes['new']++;
            } else {
                $userTypes['casual']++;
            }
        }

        return $userTypes;
    }

    /**
     * 生成用户行为总结
     * 
     * @param array $activity 活跃度数据
     * @param array $types 用户类型数据
     * @return array
     */
    private function generateUserBehaviorSummary(array $activity, array $types): array
    {
        $totalUsers = count($activity);
        
        return [
            'total_users' => $totalUsers,
            'user_type_percentages' => [
                'high_value_rate' => $totalUsers > 0 ? round($types['high_value'] / $totalUsers * 100, 1) : 0,
                'frequent_rate' => $totalUsers > 0 ? round($types['frequent'] / $totalUsers * 100, 1) : 0,
                'loyal_rate' => $totalUsers > 0 ? round($types['loyal'] / $totalUsers * 100, 1) : 0,
                'new_rate' => $totalUsers > 0 ? round($types['new'] / $totalUsers * 100, 1) : 0,
                'casual_rate' => $totalUsers > 0 ? round($types['casual'] / $totalUsers * 100, 1) : 0
            ],
            'recommendations' => $this->generateRecommendations($types, $totalUsers)
        ];
    }

    /**
     * 生成运营建议
     * 
     * @param array $types 用户类型分布
     * @param int $totalUsers 总用户数
     * @return array
     */
    private function generateRecommendations(array $types, int $totalUsers): array
    {
        $recommendations = [];
        
        if ($totalUsers == 0) {
            return ['没有足够的数据生成建议'];
        }
        
        $newUserRate = $types['new'] / $totalUsers;
        $highValueRate = $types['high_value'] / $totalUsers;
        $loyalRate = $types['loyal'] / $totalUsers;

        if ($newUserRate > 0.5) {
            $recommendations[] = '新用户比例较高，建议加强新手引导和优惠活动';
        }
        
        if ($highValueRate < 0.1) {
            $recommendations[] = '高价值用户比例偏低，建议推出VIP活动吸引高额投注';
        }
        
        if ($loyalRate < 0.2) {
            $recommendations[] = '忠诚用户比例偏低，建议增加留存机制和长期奖励';
        }
        
        if (empty($recommendations)) {
            $recommendations[] = '用户结构良好，继续保持当前运营策略';
        }

        return $recommendations;
    }

    /**
     * 获取统计类型对应的日期范围
     * 
     * @param string $statType 统计类型
     * @param string $statDate 统计日期
     * @return array
     */
    private function getDateRangeForStatType(string $statType, string $statDate): array
    {
        $date = new \DateTime($statDate);
        
        switch ($statType) {
            case self::STAT_HOURLY:
                return [
                    'start' => $date->format('Y-m-d H:00:00'),
                    'end' => $date->format('Y-m-d H:59:59')
                ];
            case self::STAT_DAILY:
                return [
                    'start' => $date->format('Y-m-d 00:00:00'),
                    'end' => $date->format('Y-m-d 23:59:59')
                ];
            case self::STAT_WEEKLY:
                $start = clone $date;
                $start->modify('monday this week');
                $end = clone $start;
                $end->modify('sunday this week');
                return [
                    'start' => $start->format('Y-m-d 00:00:00'),
                    'end' => $end->format('Y-m-d 23:59:59')
                ];
            case self::STAT_MONTHLY:
                return [
                    'start' => $date->format('Y-m-01 00:00:00'),
                    'end' => $date->format('Y-m-t 23:59:59')
                ];
            default:
                return [
                    'start' => $date->format('Y-m-d 00:00:00'),
                    'end' => $date->format('Y-m-d 23:59:59')
                ];
        }
    }

    /**
     * 计算指定时期的游戏统计
     * 
     * @param int $tableId 台桌ID
     * @param array $dateRange 日期范围
     * @return array
     */
    private function calculateGameStatsForPeriod(int $tableId, array $dateRange): array
    {
        $results = SicboGameResults::where('table_id', $tableId)
            ->where('created_at', '>=', $dateRange['start'])
            ->where('created_at', '<=', $dateRange['end'])
            ->where('status', 1)
            ->select()
            ->toArray();

        $basicStats = $this->calculateBasicStats($results);
        $hotColdNumbers = $this->calculateHotColdNumbers($results);

        return [
            'total_rounds' => count($results),
            'big_count' => $basicStats['big_count'],
            'small_count' => $basicStats['small_count'],
            'odd_count' => $basicStats['odd_count'],
            'even_count' => $basicStats['even_count'],
            'triple_count' => $basicStats['triple_count'],
            'pair_count' => $basicStats['pair_count'],
            'total_distribution' => json_encode($basicStats['total_distribution']),
            'dice_distribution' => json_encode($hotColdNumbers['dice_frequency'])
        ];
    }

    /**
     * 计算指定时期的投注统计
     * 
     * @param int $tableId 台桌ID
     * @param array $dateRange 日期范围
     * @return array
     */
    private function calculateBetStatsForPeriod(int $tableId, array $dateRange): array
    {
        $betStats = Db::table('ntp_sicbo_bet_records')
            ->where('table_id', $tableId)
            ->where('bet_time', '>=', $dateRange['start'])
            ->where('bet_time', '<=', $dateRange['end'])
            ->field([
                'SUM(bet_amount) as total_bet_amount',
                'SUM(win_amount) as total_win_amount',
                'COUNT(DISTINCT user_id) as player_count'
            ])
            ->find();

        return [
            'total_bet_amount' => $betStats['total_bet_amount'] ?? 0,
            'total_win_amount' => $betStats['total_win_amount'] ?? 0,
            'player_count' => $betStats['player_count'] ?? 0
        ];
    }

    /**
     * 清除相关缓存
     * 
     * @param int $tableId 台桌ID
     * @return void
     */
    private function clearRelatedCache(int $tableId): void
    {
        $patterns = [
            self::CACHE_PREFIX . "realtime_{$tableId}_*",
            self::CACHE_PREFIX . "historical_{$tableId}_*",
            self::CACHE_PREFIX . "betting_{$tableId}_*"
        ];

        foreach ($patterns as $pattern) {
            Cache::clear($pattern);
        }
    }
}