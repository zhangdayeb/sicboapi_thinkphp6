<?php

namespace app\model;

use think\Model;

/**
 * 资金记录模型
 * Class MoneyLog
 * @package app\model
 */
class MoneyLog extends Model
{
    // 数据表名
    public $name = 'common_pay_money_log';

    // 主键
    protected $pk = 'id';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false; // 资金记录通常不需要更新时间

    // 数据类型转换
    protected $type = [
        'id'           => 'integer',
        'type'         => 'integer',
        'status'       => 'integer',
        'money_before' => 'float',
        'money_end'    => 'float',
        'money'        => 'float',
        'uid'          => 'integer',
        'source_id'    => 'integer',
        'create_time'  => 'datetime',
    ];

    // 只读字段
    protected $readonly = [
        'id',
        'create_time',
    ];

    // 操作类型常量
    const TYPE_RECHARGE = 1;         // 充值
    const TYPE_BET = 2;              // 投注
    const TYPE_WIN = 3;              // 中奖
    const TYPE_WITHDRAW = 4;         // 提现
    const TYPE_REFUND = 5;           // 退款
    const TYPE_COMMISSION = 6;       // 佣金
    const TYPE_ADMIN_ADD = 7;        // 管理员增加
    const TYPE_ADMIN_MINUS = 8;      // 管理员扣除

    // 状态常量 - 改为9开头（骰宝相关）
    const STATUS_SICBO_BIG = 901;        // 骰宝-大
    const STATUS_SICBO_SMALL = 902;      // 骰宝-小
    const STATUS_SICBO_ODD = 903;        // 骰宝-单
    const STATUS_SICBO_EVEN = 904;       // 骰宝-双
    const STATUS_SICBO_TOTAL = 905;      // 骰宝-总和
    const STATUS_SICBO_SINGLE = 906;     // 骰宝-单骰
    const STATUS_SICBO_PAIR = 907;       // 骰宝-对子
    const STATUS_SICBO_TRIPLE = 908;     // 骰宝-三同号
    const STATUS_SICBO_COMBO = 909;      // 骰宝-组合
    const STATUS_SICBO_REFUND = 910;     // 骰宝-退款

    /**
     * 下注插入资金记录
     * @param array $info 投注信息
     * @return bool
     */
    public function order_insert_bet_money_log(array $info): bool
    {
        $status = 901; // 默认状态
        
        // 根据游戏类型设置状态（改为9开头）
        switch ($info['game_type']) {
            case 1:
                $status = self::STATUS_SICBO_BIG;
                break;
            case 2:
                $status = self::STATUS_SICBO_SMALL;
                break;
            case 3:
                $status = self::STATUS_SICBO_ODD;
                break;
            case 4:
                $status = self::STATUS_SICBO_EVEN;
                break;
            case 5:
                $status = self::STATUS_SICBO_TOTAL;
                break;
            case 6:
                $status = self::STATUS_SICBO_SINGLE;
                break;
            case 7:
                $status = self::STATUS_SICBO_PAIR;
                break;
            case 8:
                $status = self::STATUS_SICBO_TRIPLE;
                break;
            case 9:
                $status = self::STATUS_SICBO_COMBO;
                break;
        }
        
        // 设置默认押金
        $info['deposit_amt'] = $info['deposit_amt'] ?? 0;
        
        // 构建备注信息
        $mark = sprintf(
            '下注:%s,押金：%s,开始金额:%s,结束金额:%s,台桌/类型:%s-%s,靴/铺:%s-%s,赔率:ID%s',
            $info['bet_amt'],
            $info['deposit_amt'], 
            $info['before_amt'],
            $info['end_amt'],
            $info['table_id'],
            $info['game_type'],
            $info['xue_number'],
            $info['pu_number'],
            $info['game_peilv_id']
        );

        // 插入记录
        $logData = [
            'create_time' => date('Y-m-d H:i:s'),
            'type' => self::TYPE_BET,
            'status' => $status,
            'money_before' => $info['before_amt'],
            'money_end' => $info['end_amt'],
            'money' => -$info['bet_amt'], // 投注金额为负数
            'uid' => $info['user_id'],
            'source_id' => $info['source_id'],
            'mark' => $mark
        ];

        return self::insert($logData);
    }

    /**
     * 插入中奖资金记录
     * @param array $info 中奖信息
     * @return bool
     */
    public static function insertWinMoneyLog(array $info): bool
    {
        $mark = sprintf(
            '中奖派彩:%s,投注金额:%s,赔率:%s,台桌:%s,游戏局号:%s',
            $info['win_amount'],
            $info['bet_amount'],
            $info['odds'],
            $info['table_id'],
            $info['game_number']
        );

        $logData = [
            'create_time' => date('Y-m-d H:i:s'),
            'type' => self::TYPE_WIN,
            'status' => $info['status'] ?? 0,
            'money_before' => $info['before_amt'],
            'money_end' => $info['end_amt'],
            'money' => $info['win_amount'], // 中奖金额为正数
            'uid' => $info['user_id'],
            'source_id' => $info['source_id'] ?? 0,
            'mark' => $mark
        ];

        return self::insert($logData);
    }

    /**
     * 插入充值资金记录
     * @param array $info 充值信息
     * @return bool
     */
    public static function insertRechargeLog(array $info): bool
    {
        $mark = sprintf(
            '用户充值:%s,充值方式:%s,订单号:%s',
            $info['amount'],
            $info['pay_method'] ?? '未知',
            $info['order_no'] ?? ''
        );

        $logData = [
            'create_time' => date('Y-m-d H:i:s'),
            'type' => self::TYPE_RECHARGE,
            'status' => $info['status'] ?? 0,
            'money_before' => $info['before_amt'],
            'money_end' => $info['end_amt'],
            'money' => $info['amount'],
            'uid' => $info['user_id'],
            'source_id' => $info['source_id'] ?? 0,
            'mark' => $mark
        ];

        return self::insert($logData);
    }

    /**
     * 插入提现资金记录
     * @param array $info 提现信息
     * @return bool
     */
    public static function insertWithdrawLog(array $info): bool
    {
        $mark = sprintf(
            '用户提现:%s,手续费:%s,实际到账:%s,提现方式:%s',
            $info['amount'],
            $info['fee'] ?? 0,
            $info['actual_amount'] ?? $info['amount'],
            $info['withdraw_method'] ?? '未知'
        );

        $logData = [
            'create_time' => date('Y-m-d H:i:s'),
            'type' => self::TYPE_WITHDRAW,
            'status' => $info['status'] ?? 0,
            'money_before' => $info['before_amt'],
            'money_end' => $info['end_amt'],
            'money' => -$info['amount'], // 提现金额为负数
            'uid' => $info['user_id'],
            'source_id' => $info['source_id'] ?? 0,
            'mark' => $mark
        ];

        return self::insert($logData);
    }

    /**
     * 插入退款资金记录
     * @param array $info 退款信息
     * @return bool
     */
    public static function insertRefundLog(array $info): bool
    {
        $mark = sprintf(
            '投注退款:%s,原因:%s,游戏局号:%s',
            $info['amount'],
            $info['reason'] ?? '系统退款',
            $info['game_number'] ?? ''
        );

        $logData = [
            'create_time' => date('Y-m-d H:i:s'),
            'type' => self::TYPE_REFUND,
            'status' => self::STATUS_SICBO_REFUND, // 使用骰宝退款状态
            'money_before' => $info['before_amt'],
            'money_end' => $info['end_amt'],
            'money' => $info['amount'], // 退款金额为正数
            'uid' => $info['user_id'],
            'source_id' => $info['source_id'] ?? 0,
            'mark' => $mark
        ];

        return self::insert($logData);
    }

    /**
     * 状态获取器 - 兼容原有系统的获取器方法
     * @param mixed $value 状态值
     * @return mixed
     */
    public function getStatusAttr($value)
    {
        // 完整的状态类型映射（包含原有的和新增的骰宝状态）
        $type = [
            -1 => '已删除', 
            101 => '充值', 
            102 => '提现', 
            105 => '后台代理额度充值', 
            106 => '后台代理额度扣除', 
            201 => '提现',
            301 => '积分操作', 
            305 => '代理操作额度增加', 
            306 => '代理操作额度扣除',
            401 => '套餐分销奖励', 
            403 => '充值分销奖励',
            501 => '游戏', 
            502 => '龙虎下注', 
            503 => '百家乐下注', 
            506 => '牛牛下注', 
            508 => '三公下注',
            509 => '退还下注金额', 
            602 => '洗码费', 
            603 => '代理费',
            702 => '退还洗码费', 
            703 => '退还代理费',
            
            // 新增骰宝状态（9开头）
            901 => '骰宝-大',
            902 => '骰宝-小', 
            903 => '骰宝-单',
            904 => '骰宝-双',
            905 => '骰宝-总和',
            906 => '骰宝-单骰',
            907 => '骰宝-对子', 
            908 => '骰宝-三同号',
            909 => '骰宝-组合',
            910 => '骰宝-退款'
        ];

        if ($value == 'status_type') {
            $type_list = [
                ['id' => 101, 'name' => $type[101]],
                ['id' => 102, 'name' => $type[102]],
                ['id' => 105, 'name' => $type[105]],
                ['id' => 106, 'name' => $type[106]],
                ['id' => 305, 'name' => $type[305]],
                ['id' => 306, 'name' => $type[306]],
                ['id' => 602, 'name' => $type[602]],
                ['id' => 502, 'name' => $type[502]],
                ['id' => 503, 'name' => $type[503]],
                ['id' => 506, 'name' => $type[506]],
                ['id' => 508, 'name' => $type[508]],
                
                // 新增骰宝状态到类型列表
                ['id' => 901, 'name' => $type[901]],
                ['id' => 902, 'name' => $type[902]],
                ['id' => 903, 'name' => $type[903]],
                ['id' => 904, 'name' => $type[904]],
                ['id' => 905, 'name' => $type[905]],
                ['id' => 906, 'name' => $type[906]],
                ['id' => 907, 'name' => $type[907]],
                ['id' => 908, 'name' => $type[908]],
                ['id' => 909, 'name' => $type[909]],
                ['id' => 910, 'name' => $type[910]],
            ];
            return $type_list;
        }
        
        return isset($type[$value]) ? $type[$value] : $value;
    }

    /**
     * 获取用户资金记录
     * @param int $userId 用户ID
     * @param int $page 页码
     * @param int $limit 每页数量
     * @param array $filters 筛选条件
     * @return array
     */
    public static function getUserMoneyLogs(int $userId, int $page = 1, int $limit = 20, array $filters = []): array
    {
        $query = self::where('uid', $userId);
        
        // 应用筛选条件
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetweenTime('create_time', $filters['start_date'], $filters['end_date']);
        }
        
        $total = $query->count();
        $logs = $query->order('create_time desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
            'logs' => $logs,
        ];
    }

    /**
     * 获取用户资金统计
     * @param int $userId 用户ID
     * @param string $period 统计周期
     * @return array
     */
    public static function getUserMoneyStats(int $userId, string $period = 'today'): array
    {
        $query = self::where('uid', $userId);
        
        // 应用时间筛选
        switch ($period) {
            case 'today':
                $query->whereTime('create_time', 'today');
                break;
            case 'week':
                $query->whereTime('create_time', 'week');
                break;
            case 'month':
                $query->whereTime('create_time', 'month');
                break;
        }
        
        $stats = $query->field([
            'type',
            'count(*) as count',
            'sum(case when money > 0 then money else 0 end) as total_income',
            'sum(case when money < 0 then abs(money) else 0 end) as total_expense'
        ])
        ->group('type')
        ->select()
        ->toArray();
        
        $result = [
            'total_income' => 0,
            'total_expense' => 0,
            'net_amount' => 0,
            'type_stats' => []
        ];
        
        foreach ($stats as $stat) {
            $result['total_income'] += $stat['total_income'];
            $result['total_expense'] += $stat['total_expense'];
            $result['type_stats'][$stat['type']] = [
                'count' => $stat['count'],
                'income' => $stat['total_income'],
                'expense' => $stat['total_expense']
            ];
        }
        
        $result['net_amount'] = $result['total_income'] - $result['total_expense'];
        
        return $result;
    }

    /**
     * 获取骰宝投注统计
     * @param int $userId 用户ID
     * @param string $period 统计周期
     * @return array
     */
    public static function getSicboBetStats(int $userId, string $period = 'today'): array
    {
        $query = self::where('uid', $userId)
            ->where('status', '>=', 901)  // 骰宝状态码范围
            ->where('status', '<=', 910);
        
        // 应用时间筛选
        switch ($period) {
            case 'today':
                $query->whereTime('create_time', 'today');
                break;
            case 'week':
                $query->whereTime('create_time', 'week');
                break;
            case 'month':
                $query->whereTime('create_time', 'month');
                break;
        }
        
        $stats = $query->field([
            'status',
            'count(*) as bet_count',
            'sum(abs(money)) as total_amount'
        ])
        ->group('status')
        ->select()
        ->toArray();
        
        return $stats;
    }

    /**
     * 获取操作类型名称
     * @param int $type 操作类型
     * @return string
     */
    public static function getTypeName(int $type): string
    {
        $types = [
            self::TYPE_RECHARGE => '充值',
            self::TYPE_BET => '投注',
            self::TYPE_WIN => '中奖',
            self::TYPE_WITHDRAW => '提现',
            self::TYPE_REFUND => '退款',
            self::TYPE_COMMISSION => '佣金',
            self::TYPE_ADMIN_ADD => '管理员增加',
            self::TYPE_ADMIN_MINUS => '管理员扣除',
        ];
        
        return $types[$type] ?? '未知操作';
    }

    /**
     * 获取状态名称（新版本兼容9开头）
     * @param int $status 状态
     * @return string
     */
    public static function getStatusName(int $status): string
    {
        $statuses = [
            self::STATUS_SICBO_BIG => '骰宝-大',
            self::STATUS_SICBO_SMALL => '骰宝-小',
            self::STATUS_SICBO_ODD => '骰宝-单',
            self::STATUS_SICBO_EVEN => '骰宝-双',
            self::STATUS_SICBO_TOTAL => '骰宝-总和',
            self::STATUS_SICBO_SINGLE => '骰宝-单骰',
            self::STATUS_SICBO_PAIR => '骰宝-对子',
            self::STATUS_SICBO_TRIPLE => '骰宝-三同号',
            self::STATUS_SICBO_COMBO => '骰宝-组合',
            self::STATUS_SICBO_REFUND => '骰宝-退款',
        ];
        
        return $statuses[$status] ?? '其他';
    }

    /**
     * 清理过期记录
     * @param int $keepDays 保留天数
     * @return int 清理数量
     */
    public static function cleanExpiredLogs(int $keepDays = 365): int
    {
        $expireDate = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));
        
        return self::where('create_time', '<', $expireDate)->delete();
    }
}