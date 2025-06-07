<?php


namespace app\model\sicbo;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * 骰宝投注记录模型
 * Class SicboBetRecords
 * @package app\model\sicbo
 */
class SicboBetRecords extends Model
{
    use SoftDelete;

    // 数据表名
    protected $name = 'sicbo_bet_records';
    
    // 主键
    protected $pk = 'id';
    
    // 自动时间戳
    protected $autoWriteTimestamp = false; // 使用自定义时间字段
    protected $deleteTime = 'deleted_at';
    
    // 软删除时间字段默认值
    protected $defaultSoftDelete = null;

    // 数据类型转换
    protected $type = [
        'id'              => 'integer',
        'user_id'         => 'integer',
        'table_id'        => 'integer',
        'round_number'    => 'integer',
        'bet_amount'      => 'float',
        'odds'            => 'float',
        'is_win'          => 'integer',
        'win_amount'      => 'float',
        'settle_status'   => 'integer',
        'balance_before'  => 'float',
        'balance_after'   => 'float',
        'bet_time'        => 'timestamp',
        'settle_time'     => 'timestamp',
        'deleted_at'      => 'timestamp',
    ];

    // 只读字段
    protected $readonly = [
        'id',
        'user_id',
        'table_id',
        'game_number',
        'bet_time',
    ];

    // 结算状态常量
    const SETTLE_STATUS_PENDING = 0;    // 未结算
    const SETTLE_STATUS_SETTLED = 1;    // 已结算
    const SETTLE_STATUS_CANCELLED = 2;  // 已取消

    // 输赢状态常量
    const WIN_STATUS_LOSE = 0;   // 输
    const WIN_STATUS_WIN = 1;    // 赢

    /**
     * 获取用户当前局投注记录
     * @param int $userId 用户ID
     * @param string $gameNumber 游戏局号
     * @return array
     */
    public static function getCurrentBets(int $userId, string $gameNumber): array
    {
        return self::where('user_id', $userId)
            ->where('game_number', $gameNumber)
            ->where('settle_status', self::SETTLE_STATUS_PENDING)
            ->order('bet_time asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取用户投注历史
     * @param int $userId 用户ID
     * @param int $page 页码
     * @param int $limit 每页数量
     * @param array $filters 筛选条件
     * @return array
     */
    public static function getUserBetHistory(int $userId, int $page = 1, int $limit = 20, array $filters = []): array
    {
        $query = self::where('user_id', $userId);
        
        // 应用筛选条件
        if (!empty($filters['table_id'])) {
            $query->where('table_id', $filters['table_id']);
        }
        
        if (!empty($filters['bet_type'])) {
            $query->where('bet_type', $filters['bet_type']);
        }
        
        if (!empty($filters['is_win'])) {
            $query->where('is_win', $filters['is_win']);
        }
        
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetweenTime('bet_time', $filters['start_date'], $filters['end_date']);
        }
        
        $total = $query->count();
        $records = $query->order('bet_time desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
            'records' => $records,
        ];
    }

    /**
     * 创建投注记录
     * @param array $betData 投注数据
     * @return bool|int
     */
    public static function createBetRecord(array $betData)
    {
        $betData['bet_time'] = $betData['bet_time'] ?? date('Y-m-d H:i:s');
        $betData['settle_status'] = self::SETTLE_STATUS_PENDING;
        
        return self::create($betData);
    }

    /**
     * 批量创建投注记录
     * @param array $betsData 批量投注数据
     * @return bool
     */
    public static function createBatchBets(array $betsData): bool
    {
        try {
            $currentTime = date('Y-m-d H:i:s');
            
            foreach ($betsData as &$betData) {
                $betData['bet_time'] = $betData['bet_time'] ?? $currentTime;
                $betData['settle_status'] = self::SETTLE_STATUS_PENDING;
            }
            
            return self::insertAll($betsData) !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 取消用户当前局投注
     * @param int $userId 用户ID
     * @param string $gameNumber 游戏局号
     * @return bool
     */
    public static function cancelCurrentBets(int $userId, string $gameNumber): bool
    {
        return self::where('user_id', $userId)
            ->where('game_number', $gameNumber)
            ->where('settle_status', self::SETTLE_STATUS_PENDING)
            ->update([
                'settle_status' => self::SETTLE_STATUS_CANCELLED,
                'settle_time' => date('Y-m-d H:i:s')
            ]) !== false;
    }

    /**
     * 结算游戏投注
     * @param string $gameNumber 游戏局号
     * @param array $winningBets 中奖投注类型
     * @return bool
     */
    public static function settleBets(string $gameNumber, array $winningBets): bool
    {
        try {
            self::startTrans();
            
            $bets = self::where('game_number', $gameNumber)
                ->where('settle_status', self::SETTLE_STATUS_PENDING)
                ->select();
            
            foreach ($bets as $bet) {
                $isWin = in_array($bet['bet_type'], $winningBets);
                $winAmount = $isWin ? $bet['bet_amount'] * $bet['odds'] : 0;
                
                $bet->save([
                    'is_win' => $isWin ? self::WIN_STATUS_WIN : self::WIN_STATUS_LOSE,
                    'win_amount' => $winAmount,
                    'settle_status' => self::SETTLE_STATUS_SETTLED,
                    'settle_time' => date('Y-m-d H:i:s'),
                ]);
            }
            
            self::commit();
            return true;
        } catch (\Exception $e) {
            self::rollback();
            return false;
        }
    }

    /**
     * 获取游戏局的投注统计
     * @param string $gameNumber 游戏局号
     * @return array
     */
    public static function getGameBetStats(string $gameNumber): array
    {
        $stats = self::where('game_number', $gameNumber)
            ->field('bet_type, count(*) as bet_count, sum(bet_amount) as total_amount')
            ->group('bet_type')
            ->select()
            ->toArray();
        
        $result = [
            'total_bets' => 0,
            'total_amount' => 0,
            'bet_distribution' => [],
        ];
        
        foreach ($stats as $stat) {
            $result['total_bets'] += $stat['bet_count'];
            $result['total_amount'] += $stat['total_amount'];
            $result['bet_distribution'][$stat['bet_type']] = [
                'count' => $stat['bet_count'],
                'amount' => $stat['total_amount'],
            ];
        }
        
        return $result;
    }

    /**
     * 获取用户投注统计
     * @param int $userId 用户ID
     * @param string $period 统计周期 today/week/month/all
     * @return array
     */
    public static function getUserBetStats(int $userId, string $period = 'today'): array
    {
        $query = self::where('user_id', $userId)
            ->where('settle_status', self::SETTLE_STATUS_SETTLED);
        
        // 应用时间筛选
        switch ($period) {
            case 'today':
                $query->whereTime('bet_time', 'today');
                break;
            case 'week':
                $query->whereTime('bet_time', 'week');
                break;
            case 'month':
                $query->whereTime('bet_time', 'month');
                break;
            // 'all' 不需要时间限制
        }
        
        $stats = $query->field([
            'count(*) as total_bets',
            'sum(bet_amount) as total_bet_amount',
            'sum(case when is_win = 1 then win_amount else 0 end) as total_win_amount',
            'sum(case when is_win = 1 then 1 else 0 end) as win_count',
            'sum(case when is_win = 0 then bet_amount else 0 end) as total_lose_amount'
        ])->find();
        
        if (!$stats) {
            return [
                'total_bets' => 0,
                'total_bet_amount' => 0,
                'total_win_amount' => 0,
                'total_lose_amount' => 0,
                'win_count' => 0,
                'win_rate' => 0,
                'profit_loss' => 0,
            ];
        }
        
        $stats = $stats->toArray();
        $stats['win_rate'] = $stats['total_bets'] > 0 ? 
            round($stats['win_count'] / $stats['total_bets'] * 100, 2) : 0;
        $stats['profit_loss'] = $stats['total_win_amount'] - $stats['total_bet_amount'];
        
        return $stats;
    }

    /**
     * 获取台桌投注统计
     * @param int $tableId 台桌ID
     * @param string $period 统计周期
     * @return array
     */
    public static function getTableBetStats(int $tableId, string $period = 'today'): array
    {
        $query = self::where('table_id', $tableId);
        
        switch ($period) {
            case 'today':
                $query->whereTime('bet_time', 'today');
                break;
            case 'week':
                $query->whereTime('bet_time', 'week');
                break;
            case 'month':
                $query->whereTime('bet_time', 'month');
                break;
        }
        
        return $query->field([
            'count(*) as total_bets',
            'count(distinct user_id) as unique_players',
            'sum(bet_amount) as total_bet_amount',
            'sum(case when is_win = 1 then win_amount else 0 end) as total_payout',
            'avg(bet_amount) as avg_bet_amount'
        ])->find()->toArray();
    }

    /**
     * 获取热门投注类型
     * @param int $tableId 台桌ID
     * @param int $limit 获取数量
     * @return array
     */
    public static function getPopularBetTypes(int $tableId, int $limit = 10): array
    {
        return self::where('table_id', $tableId)
            ->whereTime('bet_time', 'today')
            ->field('bet_type, count(*) as bet_count, sum(bet_amount) as total_amount')
            ->group('bet_type')
            ->order('bet_count desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取用户最近投注
     * @param int $userId 用户ID
     * @param int $limit 获取数量
     * @return array
     */
    public static function getUserRecentBets(int $userId, int $limit = 10): array
    {
        return self::where('user_id', $userId)
            ->order('bet_time desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 检查用户是否在指定游戏局有投注
     * @param int $userId 用户ID
     * @param string $gameNumber 游戏局号
     * @return bool
     */
    public static function userHasBets(int $userId, string $gameNumber): bool
    {
        return self::where('user_id', $userId)
            ->where('game_number', $gameNumber)
            ->count() > 0;
    }

    /**
     * 获取用户在指定游戏局的投注总额
     * @param int $userId 用户ID
     * @param string $gameNumber 游戏局号
     * @return float
     */
    public static function getUserGameBetTotal(int $userId, string $gameNumber): float
    {
        return (float)self::where('user_id', $userId)
            ->where('game_number', $gameNumber)
            ->sum('bet_amount');
    }

    /**
     * 获取大额投注记录
     * @param float $threshold 金额阈值
     * @param int $limit 获取数量
     * @return array
     */
    public static function getHighRollerBets(float $threshold = 1000, int $limit = 50): array
    {
        return self::where('bet_amount', '>=', $threshold)
            ->whereTime('bet_time', 'today')
            ->with(['user' => function($query) {
                $query->field('id,username,nickname');
            }])
            ->order('bet_amount desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取异常投注记录（连续大额投注、异常胜率等）
     * @param array $conditions 异常条件
     * @return array
     */
    public static function getAbnormalBets(array $conditions = []): array
    {
        $query = self::alias('br');
        
        // 连续大额投注
        if (!empty($conditions['high_amount_threshold'])) {
            $query->where('bet_amount', '>=', $conditions['high_amount_threshold']);
        }
        
        // 时间范围
        if (!empty($conditions['time_range'])) {
            $query->whereTime('bet_time', $conditions['time_range']);
        }
        
        return $query->field([
            'user_id',
            'count(*) as bet_count',
            'sum(bet_amount) as total_amount',
            'avg(bet_amount) as avg_amount',
            'sum(case when is_win = 1 then 1 else 0 end) as win_count'
        ])
        ->group('user_id')
        ->having('bet_count', '>=', $conditions['min_bet_count'] ?? 10)
        ->order('total_amount desc')
        ->select()
        ->toArray();
    }

    /**
     * 导出投注记录
     * @param array $conditions 导出条件
     * @return array
     */
    public static function exportBetRecords(array $conditions = []): array
    {
        $query = self::alias('br');
        
        // 应用筛选条件
        if (!empty($conditions['user_id'])) {
            $query->where('br.user_id', $conditions['user_id']);
        }
        
        if (!empty($conditions['table_id'])) {
            $query->where('br.table_id', $conditions['table_id']);
        }
        
        if (!empty($conditions['start_date']) && !empty($conditions['end_date'])) {
            $query->whereBetweenTime('br.bet_time', $conditions['start_date'], $conditions['end_date']);
        }
        
        return $query->order('br.bet_time desc')
            ->limit($conditions['limit'] ?? 10000)
            ->select()
            ->toArray();
    }

    /**
     * 清理过期的投注记录（软删除）
     * @param int $days 保留天数
     * @return int 清理数量
     */
    public static function cleanExpiredRecords(int $days = 90): int
    {
        $expireDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return self::where('bet_time', '<', $expireDate)
            ->where('settle_status', self::SETTLE_STATUS_SETTLED)
            ->delete();
    }
}