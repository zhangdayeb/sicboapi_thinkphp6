<?php

namespace app\model\sicbo;

use think\Model;
use think\facade\Db;

/**
 * 骰宝游戏统计模型
 * Class SicboStatistics
 * @package app\model\sicbo
 */
class SicboStatistics extends Model
{
    // 数据表名
    protected $name = 'sicbo_statistics';
    
    // 主键
    protected $pk = 'id';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // 数据类型转换 - 统一使用datetime
    protected $type = [
        'id'                   => 'integer',
        'table_id'             => 'integer',
        'stat_date'            => 'date',
        'total_rounds'         => 'integer',
        'big_count'            => 'integer',
        'small_count'          => 'integer',
        'odd_count'            => 'integer',
        'even_count'           => 'integer',
        'triple_count'         => 'integer',
        'pair_count'           => 'integer',
        'total_distribution'   => 'json',
        'dice_distribution'    => 'json',
        'total_bet_amount'     => 'float',
        'total_win_amount'     => 'float',
        'player_count'         => 'integer',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    // 只读字段
    protected $readonly = [
        'id',
        'created_at',
    ];

    // 统计类型常量
    const STAT_TYPE_HOURLY = 'hourly';
    const STAT_TYPE_DAILY = 'daily';
    const STAT_TYPE_WEEKLY = 'weekly';
    const STAT_TYPE_MONTHLY = 'monthly';

    /**
     * 获取台桌指定日期的统计数据
     * @param int $tableId 台桌ID
     * @param string $date 统计日期
     * @param string $statType 统计类型
     * @return array|null
     */
    public static function getTableStats(int $tableId, string $date, string $statType = self::STAT_TYPE_DAILY): ?array
    {
        $result = self::where('table_id', $tableId)
            ->where('stat_date', $date)
            ->where('stat_type', $statType)
            ->find();
        
        return $result ? $result->toArray() : null;
    }

    /**
     * 获取台桌最新统计数据
     * @param int $tableId 台桌ID
     * @param string $statType 统计类型
     * @return array|null
     */
    public static function getLatestStats(int $tableId, string $statType = self::STAT_TYPE_DAILY): ?array
    {
        $result = self::where('table_id', $tableId)
            ->where('stat_type', $statType)
            ->order('stat_date desc')
            ->find();
        
        return $result ? $result->toArray() : null;
    }

    /**
     * 获取台桌统计趋势
     * @param int $tableId 台桌ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @param string $statType 统计类型
     * @return array
     */
    public static function getStatsTrend(int $tableId, string $startDate, string $endDate, string $statType = self::STAT_TYPE_DAILY): array
    {
        return self::where('table_id', $tableId)
            ->where('stat_type', $statType)
            ->whereBetweenTime('stat_date', $startDate, $endDate)
            ->order('stat_date asc')
            ->select()
            ->toArray();
    }

    /**
     * 创建或更新统计数据
     * @param int $tableId 台桌ID
     * @param string $date 统计日期
     * @param string $statType 统计类型
     * @param array $data 统计数据
     * @return bool
     */
    public static function createOrUpdateStats(int $tableId, string $date, string $statType, array $data): bool
    {
        $existing = self::where('table_id', $tableId)
            ->where('stat_date', $date)
            ->where('stat_type', $statType)
            ->find();
        
        $statsData = array_merge([
            'table_id' => $tableId,
            'stat_date' => $date,
            'stat_type' => $statType,
        ], $data);
        
        if ($existing) {
            return $existing->save($statsData) !== false;
        } else {
            return self::create($statsData) !== false;
        }
    }

    /**
     * 计算台桌今日实时统计
     * @param int $tableId 台桌ID
     * @return array
     */
    public static function calculateTodayStats(int $tableId): array
    {
        // 获取今日游戏结果
        $results = \app\model\sicbo\SicboGameResults::where('table_id', $tableId)
            ->whereTime('created_at', 'today')
            ->where('status', 1)
            ->field('dice1,dice2,dice3,total_points,is_big,is_odd,has_triple,has_pair')
            ->select()
            ->toArray();
        
        if (empty($results)) {
            return [
                'total_rounds' => 0,
                'big_count' => 0,
                'small_count' => 0,
                'odd_count' => 0,
                'even_count' => 0,
                'triple_count' => 0,
                'pair_count' => 0,
                'total_distribution' => [],
                'dice_distribution' => [],
            ];
        }
        
        $stats = [
            'total_rounds' => count($results),
            'big_count' => 0,
            'small_count' => 0,
            'odd_count' => 0,
            'even_count' => 0,
            'triple_count' => 0,
            'pair_count' => 0,
            'total_distribution' => [],
            'dice_distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0],
        ];
        
        // 初始化总点数分布
        for ($i = 3; $i <= 18; $i++) {
            $stats['total_distribution'][$i] = 0;
        }
        
        // 统计各项数据
        foreach ($results as $result) {
            // 大小统计
            if ($result['is_big']) {
                $stats['big_count']++;
            } else {
                $stats['small_count']++;
            }
            
            // 单双统计
            if ($result['is_odd']) {
                $stats['odd_count']++;
            } else {
                $stats['even_count']++;
            }
            
            // 特殊结果统计
            if ($result['has_triple']) {
                $stats['triple_count']++;
            }
            if ($result['has_pair']) {
                $stats['pair_count']++;
            }
            
            // 总点数分布
            $stats['total_distribution'][$result['total_points']]++;
            
            // 单骰分布
            $stats['dice_distribution'][$result['dice1']]++;
            $stats['dice_distribution'][$result['dice2']]++;
            $stats['dice_distribution'][$result['dice3']]++;
        }
        
        return $stats;
    }

    /**
     * 计算投注统计数据
     * @param int $tableId 台桌ID
     * @param string $date 统计日期
     * @return array
     */
    public static function calculateBetStats(int $tableId, string $date): array
    {
        $startTime = $date . ' 00:00:00';
        $endTime = $date . ' 23:59:59';
        
        $betStats = \app\model\sicbo\SicboBetRecords::where('table_id', $tableId)
            ->whereBetweenTime('bet_time', $startTime, $endTime)
            ->field([
                'count(distinct user_id) as player_count',
                'sum(bet_amount) as total_bet_amount', 
                'sum(case when is_win = 1 then win_amount else 0 end) as total_win_amount'
            ])
            ->find();
        
        return [
            'player_count' => $betStats['player_count'] ?? 0,
            'total_bet_amount' => (float)($betStats['total_bet_amount'] ?? 0),
            'total_win_amount' => (float)($betStats['total_win_amount'] ?? 0),
        ];
    }

    /**
     * 生成每日统计报告 - 修复事务方法
     * @param int $tableId 台桌ID
     * @param string $date 统计日期
     * @return bool
     */
    public static function generateDailyReport(int $tableId, string $date): bool
    {
        try {
            // 计算游戏统计
            $gameStats = self::calculateTodayStats($tableId);
            
            // 计算投注统计
            $betStats = self::calculateBetStats($tableId, $date);
            
            // 合并数据
            $statsData = array_merge($gameStats, $betStats);
            
            // 保存统计数据
            return self::createOrUpdateStats($tableId, $date, self::STAT_TYPE_DAILY, $statsData);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取胜率统计
     * @param int $tableId 台桌ID
     * @param string $period 统计周期
     * @return array
     */
    public static function getWinRateStats(int $tableId, string $period = 'today'): array
    {
        $query = self::where('table_id', $tableId);
        
        switch ($period) {
            case 'week':
                $query->whereTime('stat_date', 'week');
                break;
            case 'month':
                $query->whereTime('stat_date', 'month');
                break;
            default:
                $query->whereTime('stat_date', 'today');
        }
        
        $stats = $query->field([
            'sum(total_rounds) as total_rounds',
            'sum(big_count) as big_count',
            'sum(small_count) as small_count',
            'sum(odd_count) as odd_count',
            'sum(even_count) as even_count',
            'sum(triple_count) as triple_count',
            'sum(pair_count) as pair_count'
        ])->find();
        
        if (!$stats || $stats['total_rounds'] == 0) {
            return [
                'big_rate' => 0,
                'small_rate' => 0,
                'odd_rate' => 0,
                'even_rate' => 0,
                'triple_rate' => 0,
                'pair_rate' => 0,
            ];
        }
        
        $totalRounds = $stats['total_rounds'];
        
        return [
            'big_rate' => round($stats['big_count'] / $totalRounds * 100, 2),
            'small_rate' => round($stats['small_count'] / $totalRounds * 100, 2),
            'odd_rate' => round($stats['odd_count'] / $totalRounds * 100, 2),
            'even_rate' => round($stats['even_count'] / $totalRounds * 100, 2),
            'triple_rate' => round($stats['triple_count'] / $totalRounds * 100, 2),
            'pair_rate' => round($stats['pair_count'] / $totalRounds * 100, 2),
        ];
    }

    /**
     * 获取热门投注点数
     * @param int $tableId 台桌ID
     * @param int $days 统计天数
     * @return array
     */
    public static function getHotNumbers(int $tableId, int $days = 7): array
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');
        
        $stats = self::where('table_id', $tableId)
            ->where('stat_type', self::STAT_TYPE_DAILY)
            ->whereBetweenTime('stat_date', $startDate, $endDate)
            ->field('dice_distribution')
            ->select()
            ->toArray();
        
        $diceCount = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
        
        foreach ($stats as $stat) {
            if (!empty($stat['dice_distribution'])) {
                foreach ($stat['dice_distribution'] as $dice => $count) {
                    $diceCount[$dice] += $count;
                }
            }
        }
        
        arsort($diceCount);
        
        return [
            'hot_numbers' => array_slice(array_keys($diceCount), 0, 3, true),
            'cold_numbers' => array_slice(array_keys($diceCount), -3, 3, true),
            'all_counts' => $diceCount,
        ];
    }

    /**
     * 获取点数分布百分比
     * @param int $tableId 台桌ID
     * @param string $period 统计周期
     * @return array
     */
    public static function getTotalDistributionPercent(int $tableId, string $period = 'week'): array
    {
        $query = self::where('table_id', $tableId);
        
        switch ($period) {
            case 'month':
                $query->whereTime('stat_date', 'month');
                break;
            case 'today':
                $query->whereTime('stat_date', 'today');
                break;
            default:
                $query->whereTime('stat_date', 'week');
        }
        
        $stats = $query->field('total_distribution')->select()->toArray();
        
        $totalDistribution = [];
        for ($i = 3; $i <= 18; $i++) {
            $totalDistribution[$i] = 0;
        }
        
        $totalCount = 0;
        foreach ($stats as $stat) {
            if (!empty($stat['total_distribution'])) {
                foreach ($stat['total_distribution'] as $total => $count) {
                    $totalDistribution[$total] += $count;
                    $totalCount += $count;
                }
            }
        }
        
        if ($totalCount == 0) {
            return $totalDistribution;
        }
        
        // 转换为百分比
        foreach ($totalDistribution as $total => $count) {
            $totalDistribution[$total] = round($count / $totalCount * 100, 2);
        }
        
        return $totalDistribution;
    }

    /**
     * 获取营收统计
     * @param int $tableId 台桌ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return array
     */
    public static function getRevenueStats(int $tableId, string $startDate, string $endDate): array
    {
        $stats = self::where('table_id', $tableId)
            ->where('stat_type', self::STAT_TYPE_DAILY)
            ->whereBetweenTime('stat_date', $startDate, $endDate)
            ->field([
                'sum(total_bet_amount) as total_bet',
                'sum(total_win_amount) as total_payout',
                'sum(player_count) as total_players',
                'sum(total_rounds) as total_rounds',
                'avg(total_bet_amount) as avg_daily_bet'
            ])
            ->find();
        
        if (!$stats) {
            return [
                'total_bet' => 0,
                'total_payout' => 0,
                'house_win' => 0,
                'house_edge' => 0,
                'total_players' => 0,
                'total_rounds' => 0,
                'avg_daily_bet' => 0,
            ];
        }
        
        $houseWin = $stats['total_bet'] - $stats['total_payout'];
        $houseEdge = $stats['total_bet'] > 0 ? round($houseWin / $stats['total_bet'] * 100, 2) : 0;
        
        return [
            'total_bet' => (float)$stats['total_bet'],
            'total_payout' => (float)$stats['total_payout'],
            'house_win' => $houseWin,
            'house_edge' => $houseEdge,
            'total_players' => (int)$stats['total_players'],
            'total_rounds' => (int)$stats['total_rounds'],
            'avg_daily_bet' => (float)$stats['avg_daily_bet'],
        ];
    }

    /**
     * 获取所有台桌的汇总统计
     * @param string $date 统计日期
     * @param string $statType 统计类型
     * @return array
     */
    public static function getAllTablesStats(string $date, string $statType = self::STAT_TYPE_DAILY): array
    {
        return self::where('stat_date', $date)
            ->where('stat_type', $statType)
            ->field([
                'table_id',
                'total_rounds',
                'big_count',
                'small_count',
                'total_bet_amount',
                'total_win_amount',
                'player_count'
            ])
            ->select()
            ->toArray();
    }

    /**
     * 清理过期统计数据
     * @param int $keepDays 保留天数
     * @return int 清理数量
     */
    public static function cleanExpiredStats(int $keepDays = 90): int
    {
        $expireDate = date('Y-m-d', strtotime("-{$keepDays} days"));
        
        return self::where('stat_date', '<', $expireDate)->delete();
    }

    /**
     * 批量生成统计报告 - 修复事务方法
     * @param array $tableIds 台桌ID数组
     * @param string $date 统计日期
     * @return bool
     */
    public static function batchGenerateReports(array $tableIds, string $date): bool
    {
        try {
            Db::startTrans(); // 修复：使用Db::而不是self::
            
            foreach ($tableIds as $tableId) {
                self::generateDailyReport($tableId, $date);
            }
            
            Db::commit(); // 修复：使用Db::而不是self::
            return true;
        } catch (\Exception $e) {
            Db::rollback(); // 修复：使用Db::而不是self::
            return false;
        }
    }

    /**
     * 导出统计数据
     * @param array $conditions 导出条件
     * @return array
     */
    public static function exportStats(array $conditions = []): array
    {
        $query = self::alias('ss');
        
        if (!empty($conditions['table_id'])) {
            $query->where('ss.table_id', $conditions['table_id']);
        }
        
        if (!empty($conditions['stat_type'])) {
            $query->where('ss.stat_type', $conditions['stat_type']);
        }
        
        if (!empty($conditions['start_date']) && !empty($conditions['end_date'])) {
            $query->whereBetweenTime('ss.stat_date', $conditions['start_date'], $conditions['end_date']);
        }
        
        return $query->order('ss.stat_date desc')
            ->limit($conditions['limit'] ?? 1000)
            ->select()
            ->toArray();
    }

    /**
     * 获取指定小时的统计 - 新增方法
     * @param int $tableId 台桌ID  
     * @param int $hours 小时数
     * @return array
     */
    public static function getHourlyStats(int $tableId, int $hours): array
    {
        $startTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        $endTime = date('Y-m-d H:i:s');
        
        return self::where('table_id', $tableId)
            ->where('stat_type', self::STAT_TYPE_HOURLY)
            ->whereBetweenTime('created_at', $startTime, $endTime)
            ->order('created_at desc')
            ->select()
            ->toArray();
    }
}