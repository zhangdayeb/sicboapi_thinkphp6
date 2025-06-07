<?php

namespace app\model\sicbo;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * 骰宝游戏结果模型
 * Class SicboGameResults
 * @package app\model\sicbo
 */
class SicboGameResults extends Model
{
    use SoftDelete;

    // 数据表名
    protected $name = 'sicbo_game_results';
    
    // 主键
    protected $pk = 'id';
    
    // 自动时间戳 - 开启以支持软删除
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
    protected $deleteTime = 'deleted_at';
    
    // 软删除时间字段默认值
    protected $defaultSoftDelete = null;

    // 数据类型转换 - 统一使用datetime
    protected $type = [
        'id'            => 'integer',
        'table_id'      => 'integer', 
        'round_number'  => 'integer',
        'dice1'         => 'integer',
        'dice2'         => 'integer',
        'dice3'         => 'integer',
        'total_points'  => 'integer',
        'is_big'        => 'integer',
        'is_odd'        => 'integer',
        'has_triple'    => 'integer',
        'triple_number' => 'integer',
        'has_pair'      => 'integer',
        'status'        => 'integer',
        'winning_bets'  => 'json',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // 只读字段
    protected $readonly = [
        'id',
        'created_at',
    ];

    // 字段验证规则
    protected $rule = [
        'table_id'     => 'require|integer|gt:0',
        'game_number'  => 'require|max:50',
        'dice1'        => 'require|integer|between:1,6',
        'dice2'        => 'require|integer|between:1,6',
        'dice3'        => 'require|integer|between:1,6',
        'total_points' => 'require|integer|between:3,18',
    ];

    // 错误信息
    protected $message = [
        'table_id.require'     => '台桌ID不能为空',
        'game_number.require'  => '游戏局号不能为空',
        'dice1.require'        => '骰子1点数不能为空',
        'dice1.between'        => '骰子1点数必须在1-6之间',
        'dice2.require'        => '骰子2点数不能为空',
        'dice2.between'        => '骰子2点数必须在1-6之间',
        'dice3.require'        => '骰子3点数不能为空',
        'dice3.between'        => '骰子3点数必须在1-6之间',
        'total_points.require' => '总点数不能为空',
        'total_points.between' => '总点数必须在3-18之间',
    ];

    /**
     * 获取台桌最新游戏结果
     * @param int $tableId 台桌ID
     * @param int $limit 获取数量
     * @return array
     */
    public static function getLatestResults(int $tableId, int $limit = 20): array
    {
        return self::where('table_id', $tableId)
            ->where('status', 1)
            ->order('id desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 根据游戏局号获取结果
     * @param string $gameNumber 游戏局号
     * @return array|null
     */
    public static function getByGameNumber(string $gameNumber): ?array
    {
        $result = self::where('game_number', $gameNumber)
            ->where('status', 1)
            ->find();
        
        return $result ? $result->toArray() : null;
    }

    /**
     * 获取台桌今日游戏统计
     * @param int $tableId 台桌ID
     * @return array
     */
    public static function getTodayStats(int $tableId): array
    {
        $today = date('Y-m-d');
        
        return [
            'total_rounds' => self::where('table_id', $tableId)
                ->whereTime('created_at', 'today')
                ->where('status', 1)
                ->count(),
            
            'big_count' => self::where('table_id', $tableId)
                ->whereTime('created_at', 'today')
                ->where('is_big', 1)
                ->where('status', 1)
                ->count(),
                
            'small_count' => self::where('table_id', $tableId)
                ->whereTime('created_at', 'today')
                ->where('is_big', 0)
                ->where('status', 1)
                ->count(),
                
            'odd_count' => self::where('table_id', $tableId)
                ->whereTime('created_at', 'today')
                ->where('is_odd', 1)
                ->where('status', 1)
                ->count(),
                
            'even_count' => self::where('table_id', $tableId)
                ->whereTime('created_at', 'today')
                ->where('is_odd', 0)
                ->where('status', 1)
                ->count(),
                
            'triple_count' => self::where('table_id', $tableId)
                ->whereTime('created_at', 'today')
                ->where('has_triple', 1)
                ->where('status', 1)
                ->count(),
        ];
    }

    /**
     * 获取点数分布统计
     * @param int $tableId 台桌ID
     * @param string $period 统计周期 today/week/month
     * @return array
     */
    public static function getTotalPointsDistribution(int $tableId, string $period = 'today'): array
    {
        $query = self::where('table_id', $tableId)->where('status', 1);
        
        switch ($period) {
            case 'week':
                $query->whereTime('created_at', 'week');
                break;
            case 'month':
                $query->whereTime('created_at', 'month');
                break;
            default:
                $query->whereTime('created_at', 'today');
        }
        
        $results = $query->field('total_points, count(*) as count')
            ->group('total_points')
            ->select()
            ->toArray();
        
        $distribution = [];
        for ($i = 3; $i <= 18; $i++) {
            $distribution[$i] = 0;
        }
        
        foreach ($results as $result) {
            $distribution[$result['total_points']] = $result['count'];
        }
        
        return $distribution;
    }

    /**
     * 生成游戏局号
     * @param int $tableId 台桌ID
     * @return string
     */
    public static function generateGameNumber(int $tableId): string
    {
        $date = date('Ymd');
        $sequence = self::where('table_id', $tableId)
            ->whereTime('created_at', 'today')
            ->count() + 1;
        
        return sprintf('SIC%s%03d%04d', $date, $tableId, $sequence);
    }

    /**
     * 获取当前轮次号
     * @param int $tableId 台桌ID
     * @return int
     */
    public static function getCurrentRoundNumber(int $tableId): int
    {
        $count = self::where('table_id', $tableId)
            ->whereTime('created_at', 'today')
            ->count();
        
        return $count + 1;
    }

    /**
     * 创建游戏结果记录
     * @param array $data 游戏数据
     * @return bool|int
     */
    public static function createGameResult(array $data)
    {
        // 自动计算衍生字段
        $data['total_points'] = $data['dice1'] + $data['dice2'] + $data['dice3'];
        $data['is_big'] = ($data['total_points'] >= 11 && $data['total_points'] <= 17) ? 1 : 0;
        $data['is_odd'] = ($data['total_points'] % 2 === 1) ? 1 : 0;
        
        // 分析特殊结果
        $specialResults = self::analyzeSpecialResults($data['dice1'], $data['dice2'], $data['dice3']);
        $data = array_merge($data, $specialResults);
        
        // 如果没有提供游戏局号，自动生成
        if (!isset($data['game_number'])) {
            $data['game_number'] = self::generateGameNumber($data['table_id']);
        }
        
        // 如果没有提供轮次号，自动生成
        if (!isset($data['round_number'])) {
            $data['round_number'] = self::getCurrentRoundNumber($data['table_id']);
        }
        
        return self::create($data);
    }

    /**
     * 分析特殊结果（三同号、对子等）
     * @param int $dice1
     * @param int $dice2
     * @param int $dice3
     * @return array
     */
    private static function analyzeSpecialResults(int $dice1, int $dice2, int $dice3): array
    {
        $dices = [$dice1, $dice2, $dice3];
        $counts = array_count_values($dices);
        
        $result = [
            'has_triple' => 0,
            'triple_number' => null,
            'has_pair' => 0,
            'pair_numbers' => null,
        ];
        
        foreach ($counts as $number => $count) {
            if ($count === 3) {
                $result['has_triple'] = 1;
                $result['triple_number'] = $number;
            } elseif ($count === 2) {
                $result['has_pair'] = 1;
                $result['pair_numbers'] = (string)$number;
            }
        }
        
        return $result;
    }

    /**
     * 获取热冷号码统计
     * @param int $tableId 台桌ID
     * @param int $limit 最近多少局
     * @return array
     */
    public static function getHotColdNumbers(int $tableId, int $limit = 100): array
    {
        $results = self::where('table_id', $tableId)
            ->where('status', 1)
            ->order('id desc')
            ->limit($limit)
            ->field('dice1,dice2,dice3')
            ->select()
            ->toArray();
        
        $numberCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
        
        foreach ($results as $result) {
            $numberCounts[$result['dice1']]++;
            $numberCounts[$result['dice2']]++;
            $numberCounts[$result['dice3']]++;
        }
        
        arsort($numberCounts);
        $hot = array_slice(array_keys($numberCounts), 0, 3, true);
        $cold = array_slice(array_keys($numberCounts), -3, 3, true);
        
        return [
            'hot_numbers' => $hot,
            'cold_numbers' => array_reverse($cold),
            'all_counts' => $numberCounts,
        ];
    }

    /**
     * 获取连续趋势
     * @param int $tableId 台桌ID
     * @param int $limit 最近多少局
     * @return array
     */
    public static function getTrends(int $tableId, int $limit = 50): array
    {
        $results = self::where('table_id', $tableId)
            ->where('status', 1)
            ->order('id desc')
            ->limit($limit)
            ->field('is_big,is_odd,total_points')
            ->select()
            ->toArray();
        
        if (empty($results)) {
            return [];
        }
        
        // 反转数组，按时间正序分析
        $results = array_reverse($results);
        
        $trends = [
            'big_streak' => 0,
            'small_streak' => 0,
            'odd_streak' => 0,
            'even_streak' => 0,
            'current_big_trend' => '',
            'current_odd_trend' => '',
        ];
        
        // 分析大小趋势
        $currentBigStreak = 0;
        $lastBig = null;
        foreach ($results as $result) {
            if ($lastBig === null || $lastBig === $result['is_big']) {
                $currentBigStreak++;
            } else {
                $currentBigStreak = 1;
            }
            $lastBig = $result['is_big'];
        }
        
        $trends['current_big_trend'] = $lastBig ? 'big' : 'small';
        $trends[$lastBig ? 'big_streak' : 'small_streak'] = $currentBigStreak;
        
        // 分析单双趋势
        $currentOddStreak = 0;
        $lastOdd = null;
        foreach ($results as $result) {
            if ($lastOdd === null || $lastOdd === $result['is_odd']) {
                $currentOddStreak++;
            } else {
                $currentOddStreak = 1;
            }
            $lastOdd = $result['is_odd'];
        }
        
        $trends['current_odd_trend'] = $lastOdd ? 'odd' : 'even';
        $trends[$lastOdd ? 'odd_streak' : 'even_streak'] = $currentOddStreak;
        
        return $trends;
    }

    /**
     * 检查游戏结果是否存在
     * @param string $gameNumber 游戏局号
     * @return bool
     */
    public static function gameExists(string $gameNumber): bool
    {
        return self::where('game_number', $gameNumber)->count() > 0;
    }

    /**
     * 软删除游戏结果
     * @param string $gameNumber 游戏局号
     * @return bool
     */
    public static function softDeleteGame(string $gameNumber): bool
    {
        return self::where('game_number', $gameNumber)->delete();
    }

    /**
     * 获取台桌最后一局游戏
     * @param int $tableId 台桌ID
     * @return array|null
     */
    public static function getLastGame(int $tableId): ?array
    {
        $result = self::where('table_id', $tableId)
            ->where('status', 1)
            ->order('id desc')
            ->find();
        
        return $result ? $result->toArray() : null;
    }
}