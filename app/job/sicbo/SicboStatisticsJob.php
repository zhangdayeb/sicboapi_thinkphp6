<?php



namespace app\job\sicbo;

use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboBetRecords;
use app\model\sicbo\SicboStatistics;
use app\model\DianjiTable;
use app\service\sicbo\SicboStatisticsService;
use think\queue\Job;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

/**
 * 骰宝统计更新任务
 * 负责定时更新游戏统计数据、计算趋势分析、维护统计表等
 */
class SicboStatisticsJob
{
    /**
     * 统计类型常量
     */
    private const STAT_REALTIME = 'realtime';   // 实时统计
    private const STAT_HOURLY = 'hourly';       // 小时统计
    private const STAT_DAILY = 'daily';         // 日统计
    private const STAT_WEEKLY = 'weekly';       // 周统计
    private const STAT_MONTHLY = 'monthly';     // 月统计

    /**
     * 缓存键名前缀
     */
    private const CACHE_PREFIX = 'sicbo_stats_job_';

    /**
     * 统计服务实例
     */
    private SicboStatisticsService $statisticsService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->statisticsService = new SicboStatisticsService();
    }

    /**
     * 执行任务
     * 
     * @param Job $job 任务对象
     * @param array $data 任务数据
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        try {
            $taskType = $data['task_type'] ?? 'daily_update';
            $tableId = $data['table_id'] ?? null;
            $statDate = $data['stat_date'] ?? date('Y-m-d');
            $statType = $data['stat_type'] ?? self::STAT_DAILY;

            Log::info("开始执行骰宝统计任务", [
                'task_type' => $taskType,
                'table_id' => $tableId,
                'stat_date' => $statDate,
                'stat_type' => $statType
            ]);

            $result = false;

            // 根据任务类型执行不同的统计操作
            switch ($taskType) {
                case 'game_end_update':
                    // 游戏结束后的实时统计更新
                    $result = $this->updateGameEndStatistics($data);
                    break;

                case 'hourly_update':
                    // 小时统计更新
                    $result = $this->updateHourlyStatistics($tableId, $statDate);
                    break;

                case 'daily_update':
                    // 日统计更新
                    $result = $this->updateDailyStatistics($tableId, $statDate);
                    break;

                case 'weekly_update':
                    // 周统计更新
                    $result = $this->updateWeeklyStatistics($tableId, $statDate);
                    break;

                case 'monthly_update':
                    // 月统计更新
                    $result = $this->updateMonthlyStatistics($tableId, $statDate);
                    break;

                case 'cache_refresh':
                    // 缓存刷新
                    $result = $this->refreshStatisticsCache($tableId);
                    break;

                case 'data_cleanup':
                    // 数据清理
                    $result = $this->cleanupOldStatistics($data);
                    break;

                case 'full_rebuild':
                    // 完整重建统计
                    $result = $this->rebuildAllStatistics($tableId, $data);
                    break;

                default:
                    Log::warning("未知的统计任务类型: {$taskType}");
                    $job->delete();
                    return;
            }

            if ($result) {
                Log::info("骰宝统计任务执行成功", [
                    'task_type' => $taskType,
                    'table_id' => $tableId
                ]);
                $job->delete();
            } else {
                Log::error("骰宝统计任务执行失败", [
                    'task_type' => $taskType,
                    'table_id' => $tableId
                ]);
                
                // 重试机制
                if ($job->attempts() < 3) {
                    $job->release(300); // 5分钟后重试
                } else {
                    $job->delete();
                }
            }

        } catch (\Exception $e) {
            Log::error("骰宝统计任务异常: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'data' => $data
            ]);
            
            if ($job->attempts() < 3) {
                $job->release(300);
            } else {
                $job->delete();
            }
        }
    }

    /**
     * 游戏结束后统计更新
     * 
     * @param array $data 任务数据
     * @return bool
     */
    private function updateGameEndStatistics(array $data): bool
    {
        try {
            $gameNumber = $data['game_number'] ?? '';
            $tableId = $data['table_id'] ?? 0;

            if (empty($gameNumber) || empty($tableId)) {
                Log::error('游戏结束统计更新参数不完整', $data);
                return false;
            }

            // 1. 更新实时统计缓存
            $this->updateRealtimeCache($tableId);

            // 2. 检查是否需要更新小时统计
            $currentHour = date('H');
            $lastUpdateHour = Cache::get(self::CACHE_PREFIX . "last_hour_update_{$tableId}", -1);
            
            if ($currentHour != $lastUpdateHour) {
                $this->updateHourlyStatistics($tableId, date('Y-m-d H:00:00'));
                Cache::set(self::CACHE_PREFIX . "last_hour_update_{$tableId}", $currentHour, 3600);
            }

            // 3. 更新游戏趋势数据
            $this->updateGameTrends($tableId, $gameNumber);

            // 4. 计算热冷号码
            $this->updateHotColdNumbers($tableId);

            // 5. 更新连续统计
            $this->updateStreakStatistics($tableId);

            return true;

        } catch (\Exception $e) {
            Log::error("游戏结束统计更新失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新小时统计
     * 
     * @param int|null $tableId 台桌ID
     * @param string $statDate 统计时间
     * @return bool
     */
    private function updateHourlyStatistics(?int $tableId, string $statDate): bool
    {
        try {
            $tables = $this->getTargetTables($tableId);
            $hour = date('H', strtotime($statDate));
            $date = date('Y-m-d', strtotime($statDate));

            foreach ($tables as $table) {
                $dateRange = [
                    'start' => "{$date} {$hour}:00:00",
                    'end' => "{$date} {$hour}:59:59"
                ];

                $statsData = $this->calculatePeriodStatistics($table['id'], $dateRange);
                
                if ($statsData) {
                    $this->saveStatistics($table['id'], $date, self::STAT_HOURLY, $statsData);
                }
            }

            return true;

        } catch (\Exception $e) {
            Log::error("小时统计更新失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新日统计
     * 
     * @param int|null $tableId 台桌ID
     * @param string $statDate 统计日期
     * @return bool
     */
    private function updateDailyStatistics(?int $tableId, string $statDate): bool
    {
        try {
            $tables = $this->getTargetTables($tableId);

            foreach ($tables as $table) {
                $dateRange = [
                    'start' => "{$statDate} 00:00:00",
                    'end' => "{$statDate} 23:59:59"
                ];

                $statsData = $this->calculatePeriodStatistics($table['id'], $dateRange);
                
                if ($statsData) {
                    $this->saveStatistics($table['id'], $statDate, self::STAT_DAILY, $statsData);
                }

                // 更新台桌的日统计缓存
                $this->updateTableDailyCache($table['id'], $statDate);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("日统计更新失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新周统计
     * 
     * @param int|null $tableId 台桌ID
     * @param string $statDate 统计日期
     * @return bool
     */
    private function updateWeeklyStatistics(?int $tableId, string $statDate): bool
    {
        try {
            $tables = $this->getTargetTables($tableId);
            
            // 计算周的开始和结束日期
            $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($statDate)));
            $weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($statDate)));

            foreach ($tables as $table) {
                $dateRange = [
                    'start' => "{$weekStart} 00:00:00",
                    'end' => "{$weekEnd} 23:59:59"
                ];

                $statsData = $this->calculatePeriodStatistics($table['id'], $dateRange);
                
                if ($statsData) {
                    $this->saveStatistics($table['id'], $weekStart, self::STAT_WEEKLY, $statsData);
                }
            }

            return true;

        } catch (\Exception $e) {
            Log::error("周统计更新失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新月统计
     * 
     * @param int|null $tableId 台桌ID
     * @param string $statDate 统计日期
     * @return bool
     */
    private function updateMonthlyStatistics(?int $tableId, string $statDate): bool
    {
        try {
            $tables = $this->getTargetTables($tableId);
            
            // 计算月的开始和结束日期
            $monthStart = date('Y-m-01', strtotime($statDate));
            $monthEnd = date('Y-m-t', strtotime($statDate));

            foreach ($tables as $table) {
                $dateRange = [
                    'start' => "{$monthStart} 00:00:00",
                    'end' => "{$monthEnd} 23:59:59"
                ];

                $statsData = $this->calculatePeriodStatistics($table['id'], $dateRange);
                
                if ($statsData) {
                    $this->saveStatistics($table['id'], $monthStart, self::STAT_MONTHLY, $statsData);
                }
            }

            return true;

        } catch (\Exception $e) {
            Log::error("月统计更新失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 刷新统计缓存
     * 
     * @param int|null $tableId 台桌ID
     * @return bool
     */
    private function refreshStatisticsCache(?int $tableId): bool
    {
        try {
            $tables = $this->getTargetTables($tableId);

            foreach ($tables as $table) {
                // 清除旧缓存
                $this->clearStatisticsCache($table['id']);

                // 预热新缓存
                $this->statisticsService->getRealtimeStats($table['id'], 50);
                $this->statisticsService->getHistoricalStats($table['id'], 'today');
                $this->statisticsService->getHistoricalStats($table['id'], 'week');
                $this->statisticsService->getBettingStats($table['id'], 'today');
            }

            return true;

        } catch (\Exception $e) {
            Log::error("刷新统计缓存失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 清理旧统计数据
     * 
     * @param array $data 任务数据
     * @return bool
     */
    private function cleanupOldStatistics(array $data): bool
    {
        try {
            $keepDays = $data['keep_days'] ?? 90; // 默认保留90天
            $cleanupDate = date('Y-m-d', strtotime("-{$keepDays} days"));

            // 清理旧的小时统计数据
            $hourlyDeleted = SicboStatistics::where('stat_type', self::STAT_HOURLY)
                ->where('stat_date', '<', $cleanupDate)
                ->delete();

            // 清理旧的游戏结果(可选，根据业务需求)
            if ($data['cleanup_game_results'] ?? false) {
                $gameResultsDeleted = SicboGameResults::where('created_at', '<', $cleanupDate . ' 00:00:00')
                    ->where('status', 1)
                    ->delete();
                    
                Log::info("清理旧游戏结果", ['deleted_count' => $gameResultsDeleted]);
            }

            // 清理旧的投注记录(可选)
            if ($data['cleanup_bet_records'] ?? false) {
                $betRecordsDeleted = SicboBetRecords::where('bet_time', '<', $cleanupDate . ' 00:00:00')
                    ->where('settle_status', 1)
                    ->delete();
                    
                Log::info("清理旧投注记录", ['deleted_count' => $betRecordsDeleted]);
            }

            Log::info("统计数据清理完成", [
                'cleanup_date' => $cleanupDate,
                'hourly_deleted' => $hourlyDeleted
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("清理旧统计数据失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 完整重建统计数据
     * 
     * @param int|null $tableId 台桌ID
     * @param array $data 任务数据
     * @return bool
     */
    private function rebuildAllStatistics(?int $tableId, array $data): bool
    {
        try {
            $startDate = $data['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $data['end_date'] ?? date('Y-m-d');
            $tables = $this->getTargetTables($tableId);

            foreach ($tables as $table) {
                Log::info("开始重建台桌统计", [
                    'table_id' => $table['id'],
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);

                // 重建日统计
                $currentDate = $startDate;
                while ($currentDate <= $endDate) {
                    $this->updateDailyStatistics($table['id'], $currentDate);
                    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                }

                // 重建周统计
                $currentWeek = date('Y-m-d', strtotime('monday', strtotime($startDate)));
                $endWeek = date('Y-m-d', strtotime('monday', strtotime($endDate)));
                
                while ($currentWeek <= $endWeek) {
                    $this->updateWeeklyStatistics($table['id'], $currentWeek);
                    $currentWeek = date('Y-m-d', strtotime($currentWeek . ' +1 week'));
                }

                // 重建月统计
                $currentMonth = date('Y-m-01', strtotime($startDate));
                $endMonth = date('Y-m-01', strtotime($endDate));
                
                while ($currentMonth <= $endMonth) {
                    $this->updateMonthlyStatistics($table['id'], $currentMonth);
                    $currentMonth = date('Y-m-01', strtotime($currentMonth . ' +1 month'));
                }

                // 刷新缓存
                $this->refreshStatisticsCache($table['id']);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("重建统计数据失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取目标台桌列表
     * 
     * @param int|null $tableId 台桌ID
     * @return array
     */
    private function getTargetTables(?int $tableId): array
    {
        if ($tableId) {
            $table = DianjiTable::where('id', $tableId)
                ->where('game_type', 9) // 骰宝游戏类型
                ->find();
                
            return $table ? [$table->toArray()] : [];
        }

        // 获取所有骰宝台桌
        return DianjiTable::where('game_type', 9)
            ->where('status', 1)
            ->select()
            ->toArray();
    }

    /**
     * 计算指定时期的统计数据
     * 
     * @param int $tableId 台桌ID
     * @param array $dateRange 时间范围
     * @return array|null
     */
    private function calculatePeriodStatistics(int $tableId, array $dateRange): ?array
    {
        try {
            // 获取游戏结果统计
            $gameStats = $this->calculateGameStatsForPeriod($tableId, $dateRange);
            
            // 获取投注统计
            $betStats = $this->calculateBetStatsForPeriod($tableId, $dateRange);
            
            // 合并统计数据
            return array_merge($gameStats, $betStats);

        } catch (\Exception $e) {
            Log::error("计算时期统计失败: " . $e->getMessage(), [
                'table_id' => $tableId,
                'date_range' => $dateRange
            ]);
            return null;
        }
    }

    /**
     * 计算游戏结果统计
     * 
     * @param int $tableId 台桌ID
     * @param array $dateRange 时间范围
     * @return array
     */
    private function calculateGameStatsForPeriod(int $tableId, array $dateRange): array
    {
        $query = SicboGameResults::where('table_id', $tableId)
            ->where('created_at', '>=', $dateRange['start'])
            ->where('created_at', '<=', $dateRange['end'])
            ->where('status', 1);

        // 基础统计
        $totalRounds = $query->count();
        $bigCount = $query->where('is_big', 1)->count();
        $smallCount = $query->where('is_big', 0)->count();
        $oddCount = $query->where('is_odd', 1)->count();
        $evenCount = $query->where('is_odd', 0)->count();
        $tripleCount = $query->where('has_triple', 1)->count();
        $pairCount = $query->where('has_pair', 1)->count();

        // 总点数分布统计
        $totalDistribution = [];
        for ($i = 3; $i <= 18; $i++) {
            $totalDistribution[$i] = SicboGameResults::where('table_id', $tableId)
                ->where('created_at', '>=', $dateRange['start'])
                ->where('created_at', '<=', $dateRange['end'])
                ->where('total_points', $i)
                ->where('status', 1)
                ->count();
        }

        // 单骰分布统计
        $diceDistribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
        $diceResults = SicboGameResults::where('table_id', $tableId)
            ->where('created_at', '>=', $dateRange['start'])
            ->where('created_at', '<=', $dateRange['end'])
            ->where('status', 1)
            ->field('dice1,dice2,dice3')
            ->select();

        foreach ($diceResults as $result) {
            $diceDistribution[$result['dice1']]++;
            $diceDistribution[$result['dice2']]++;
            $diceDistribution[$result['dice3']]++;
        }

        return [
            'total_rounds' => $totalRounds,
            'big_count' => $bigCount,
            'small_count' => $smallCount,
            'odd_count' => $oddCount,
            'even_count' => $evenCount,
            'triple_count' => $tripleCount,
            'pair_count' => $pairCount,
            'total_distribution' => json_encode($totalDistribution),
            'dice_distribution' => json_encode($diceDistribution)
        ];
    }

    /**
     * 计算投注统计
     * 
     * @param int $tableId 台桌ID
     * @param array $dateRange 时间范围
     * @return array
     */
    private function calculateBetStatsForPeriod(int $tableId, array $dateRange): array
    {
        $betStats = Db::table('sicbo_bet_records')
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
     * 保存统计数据
     * 
     * @param int $tableId 台桌ID
     * @param string $statDate 统计日期
     * @param string $statType 统计类型
     * @param array $statsData 统计数据
     * @return bool
     */
    private function saveStatistics(int $tableId, string $statDate, string $statType, array $statsData): bool
    {
        try {
            $data = array_merge($statsData, [
                'table_id' => $tableId,
                'stat_date' => $statDate,
                'stat_type' => $statType,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 检查是否已存在
            $existing = SicboStatistics::where([
                'table_id' => $tableId,
                'stat_date' => $statDate,
                'stat_type' => $statType
            ])->find();

            if ($existing) {
                $existing->save($data);
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                SicboStatistics::create($data);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("保存统计数据失败: " . $e->getMessage(), [
                'table_id' => $tableId,
                'stat_date' => $statDate,
                'stat_type' => $statType
            ]);
            return false;
        }
    }

    /**
     * 更新实时统计缓存
     * 
     * @param int $tableId 台桌ID
     * @return void
     */
    private function updateRealtimeCache(int $tableId): void
    {
        try {
            // 清除旧的实时统计缓存
            Cache::delete("sicbo_stats_realtime_{$tableId}_50");
            Cache::delete("sicbo_stats_realtime_{$tableId}_100");
            
            // 预热新的实时统计缓存
            $this->statisticsService->getRealtimeStats($tableId, 50);

        } catch (\Exception $e) {
            Log::error("更新实时统计缓存失败: " . $e->getMessage());
        }
    }

    /**
     * 更新游戏趋势数据
     * 
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @return void
     */
    private function updateGameTrends(int $tableId, string $gameNumber): void
    {
        try {
            $cacheKey = "sicbo_trends_{$tableId}";
            $trends = Cache::get($cacheKey, []);
            
            // 获取最新游戏结果
            $gameResult = SicboGameResults::where('game_number', $gameNumber)
                ->where('table_id', $tableId)
                ->find();
                
            if ($gameResult) {
                $trends[] = [
                    'game_number' => $gameNumber,
                    'total_points' => $gameResult->total_points,
                    'is_big' => $gameResult->is_big,
                    'is_odd' => $gameResult->is_odd,
                    'has_triple' => $gameResult->has_triple,
                    'has_pair' => $gameResult->has_pair,
                    'created_at' => $gameResult->created_at
                ];
                
                // 只保留最近100条趋势数据
                if (count($trends) > 100) {
                    $trends = array_slice($trends, -100);
                }
                
                Cache::set($cacheKey, $trends, 3600);
            }

        } catch (\Exception $e) {
            Log::error("更新游戏趋势失败: " . $e->getMessage());
        }
    }

    /**
     * 更新热冷号码统计
     * 
     * @param int $tableId 台桌ID
     * @return void
     */
    private function updateHotColdNumbers(int $tableId): void
    {
        try {
            // 获取最近50局的数据
            $recentResults = SicboGameResults::where('table_id', $tableId)
                ->where('status', 1)
                ->order('created_at desc')
                ->limit(50)
                ->field('dice1,dice2,dice3,total_points')
                ->select()
                ->toArray();

            $diceFrequency = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
            $totalFrequency = [];

            foreach ($recentResults as $result) {
                $diceFrequency[$result['dice1']]++;
                $diceFrequency[$result['dice2']]++;
                $diceFrequency[$result['dice3']]++;
                
                $total = $result['total_points'];
                $totalFrequency[$total] = ($totalFrequency[$total] ?? 0) + 1;
            }

            $hotColdData = [
                'dice_frequency' => $diceFrequency,
                'total_frequency' => $totalFrequency,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            Cache::set("sicbo_hot_cold_{$tableId}", $hotColdData, 600); // 10分钟缓存

        } catch (\Exception $e) {
            Log::error("更新热冷号码失败: " . $e->getMessage());
        }
    }

    /**
     * 更新连续统计
     * 
     * @param int $tableId 台桌ID
     * @return void
     */
    private function updateStreakStatistics(int $tableId): void
    {
        try {
            // 获取最近30局的结果
            $recentResults = SicboGameResults::where('table_id', $tableId)
                ->where('status', 1)
                ->order('created_at desc')
                ->limit(30)
                ->field('is_big,is_odd')
                ->select()
                ->toArray();

            if (empty($recentResults)) {
                return;
            }

            $streaks = [
                'big_streak' => 0,
                'small_streak' => 0,
                'odd_streak' => 0,
                'even_streak' => 0
            ];

            // 计算当前连续
            foreach ($recentResults as $result) {
                if ($result['is_big'] == 1) {
                    $streaks['big_streak']++;
                    $streaks['small_streak'] = 0;
                } else {
                    $streaks['small_streak']++;
                    $streaks['big_streak'] = 0;
                }

                if ($result['is_odd'] == 1) {
                    $streaks['odd_streak']++;
                    $streaks['even_streak'] = 0;
                } else {
                    $streaks['even_streak']++;
                    $streaks['odd_streak'] = 0;
                }
                
                break; // 只看第一条（最新的）结果
            }

            Cache::set("sicbo_streaks_{$tableId}", $streaks, 600);

        } catch (\Exception $e) {
            Log::error("更新连续统计失败: " . $e->getMessage());
        }
    }

    /**
     * 更新台桌日统计缓存
     * 
     * @param int $tableId 台桌ID
     * @param string $statDate 统计日期
     * @return void
     */
    private function updateTableDailyCache(int $tableId, string $statDate): void
    {
        try {
            $cacheKey = "sicbo_daily_stats_{$tableId}_{$statDate}";
            
            // 清除旧缓存
            Cache::delete($cacheKey);
            
            // 重新获取并缓存
            $this->statisticsService->getHistoricalStats($tableId, 'today');

        } catch (\Exception $e) {
            Log::error("更新台桌日统计缓存失败: " . $e->getMessage());
        }
    }

    /**
     * 清除统计缓存
     * 
     * @param int $tableId 台桌ID
     * @return void
     */
    private function clearStatisticsCache(int $tableId): void
    {
        $patterns = [
            "sicbo_stats_realtime_{$tableId}_*",
            "sicbo_stats_historical_{$tableId}_*",
            "sicbo_stats_betting_{$tableId}_*",
            "sicbo_trends_{$tableId}",
            "sicbo_hot_cold_{$tableId}",
            "sicbo_streaks_{$tableId}",
            "sicbo_daily_stats_{$tableId}_*"
        ];

        foreach ($patterns as $pattern) {
            // 这里需要根据缓存驱动实现通配符删除
            // Redis: Cache::store('redis')->delete($pattern);
            // 或者逐个删除已知的缓存键
        }
    }

    /**
     * 静态方法：推送游戏结束统计任务
     * 
     * @param string $gameNumber 游戏局号
     * @param int $tableId 台桌ID
     * @return bool
     */
    public static function pushGameEndUpdate(string $gameNumber, int $tableId): bool
    {
        try {
            $jobData = [
                'task_type' => 'game_end_update',
                'game_number' => $gameNumber,
                'table_id' => $tableId,
                'timestamp' => time()
            ];

            // Queue::push(self::class, $jobData, 'statistics');
            
            Log::info("推送游戏结束统计任务", $jobData);
            return true;

        } catch (\Exception $e) {
            Log::error("推送游戏结束统计任务失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 静态方法：推送定时统计任务
     * 
     * @param string $statType 统计类型
     * @param int|null $tableId 台桌ID
     * @param string|null $statDate 统计日期
     * @return bool
     */
    public static function pushScheduledUpdate(string $statType, ?int $tableId = null, ?string $statDate = null): bool
    {
        try {
            $jobData = [
                'task_type' => $statType . '_update',
                'table_id' => $tableId,
                'stat_date' => $statDate ?? date('Y-m-d'),
                'stat_type' => $statType,
                'timestamp' => time()
            ];

            // Queue::push(self::class, $jobData, 'statistics');
            
            Log::info("推送定时统计任务", $jobData);
            return true;

        } catch (\Exception $e) {
            Log::error("推送定时统计任务失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 静态方法：推送缓存刷新任务
     * 
     * @param int|null $tableId 台桌ID
     * @return bool
     */
    public static function pushCacheRefresh(?int $tableId = null): bool
    {
        try {
            $jobData = [
                'task_type' => 'cache_refresh',
                'table_id' => $tableId,
                'timestamp' => time()
            ];

            // Queue::push(self::class, $jobData, 'statistics');
            
            Log::info("推送缓存刷新任务", $jobData);
            return true;

        } catch (\Exception $e) {
            Log::error("推送缓存刷新任务失败: " . $e->getMessage());
            return false;
        }
    }
}