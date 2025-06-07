<?php

namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * 用户模型
 * Class UserModel
 * @package app\model
 */
class UserModel extends Model
{
    use SoftDelete;

    // 数据表名
    protected $name = 'common_user';
    
    // 主键
    protected $pk = 'id';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'deleted_at';

    // 数据类型转换
    protected $type = [
        'id'              => 'integer',
        'money_balance'   => 'float',
        'frozen_amount'   => 'float',
        'total_recharge'  => 'float',
        'total_withdraw'  => 'float',
        'status'          => 'integer',
        'level'           => 'integer',
        'create_time'     => 'datetime',
        'update_time'     => 'datetime',
        'deleted_at'      => 'datetime',
    ];

    // 只读字段
    protected $readonly = [
        'id',
        'create_time',
    ];

    // 隐藏字段
    protected $hidden = [
        'password',
        'pay_password',
        'deleted_at'
    ];

    // 用户状态常量
    const STATUS_DISABLED = 0;       // 禁用
    const STATUS_NORMAL = 1;         // 正常
    const STATUS_FROZEN = 2;         // 冻结

    /**
     * 根据用户名获取用户
     * @param string $username 用户名
     * @return array|null
     */
    public static function getUserByUsername(string $username): ?array
    {
        $result = self::where('username', $username)
            ->where('status', self::STATUS_NORMAL)
            ->find();
        
        return $result ? $result->toArray() : null;
    }

    /**
     * 根据用户ID获取用户信息
     * @param int $userId 用户ID
     * @return array|null
     */
    public static function getUserById(int $userId): ?array
    {
        $result = self::find($userId);
        return $result ? $result->toArray() : null;
    }

    /**
     * 获取用户余额信息
     * @param int $userId 用户ID
     * @return array
     */
    public static function getUserBalance(int $userId): array
    {
        $user = self::find($userId);
        
        if (!$user) {
            return [
                'total_balance' => 0,
                'frozen_amount' => 0,
                'available_balance' => 0,
            ];
        }
        
        return [
            'total_balance' => $user->money_balance,
            'frozen_amount' => $user->frozen_amount ?? 0,
            'available_balance' => $user->money_balance - ($user->frozen_amount ?? 0),
        ];
    }

    /**
     * 更新用户余额
     * @param int $userId 用户ID
     * @param float $amount 金额（正数增加，负数减少）
     * @param string $type 操作类型
     * @return bool
     */
    public static function updateBalance(int $userId, float $amount, string $type = 'manual'): bool
    {
        $user = self::find($userId);
        
        if (!$user) {
            return false;
        }
        
        // 检查余额是否足够（减少时）
        if ($amount < 0 && $user->money_balance < abs($amount)) {
            return false;
        }
        
        $user->money_balance += $amount;
        
        return $user->save() !== false;
    }

    /**
     * 冻结/解冻用户资金
     * @param int $userId 用户ID
     * @param float $amount 金额
     * @param bool $freeze true=冻结，false=解冻
     * @return bool
     */
    public static function freezeBalance(int $userId, float $amount, bool $freeze = true): bool
    {
        $user = self::find($userId);
        
        if (!$user) {
            return false;
        }
        
        if ($freeze) {
            // 冻结资金
            $availableBalance = $user->money_balance - ($user->frozen_amount ?? 0);
            if ($availableBalance < $amount) {
                return false; // 可用余额不足
            }
            $user->frozen_amount = ($user->frozen_amount ?? 0) + $amount;
        } else {
            // 解冻资金
            if (($user->frozen_amount ?? 0) < $amount) {
                return false; // 冻结金额不足
            }
            $user->frozen_amount = ($user->frozen_amount ?? 0) - $amount;
        }
        
        return $user->save() !== false;
    }

    /**
     * 扣除用户余额（投注时使用）
     * @param int $userId 用户ID
     * @param float $amount 扣除金额
     * @param string $reason 扣除原因
     * @return bool
     */
    public static function deductBalance(int $userId, float $amount, string $reason = 'bet'): bool
    {
        $user = self::find($userId);
        
        if (!$user) {
            return false;
        }
        
        // 检查可用余额
        $availableBalance = $user->money_balance - ($user->frozen_amount ?? 0);
        if ($availableBalance < $amount) {
            return false;
        }
        
        $user->money_balance -= $amount;
        
        return $user->save() !== false;
    }

    /**
     * 增加用户余额（中奖时使用）
     * @param int $userId 用户ID
     * @param float $amount 增加金额
     * @param string $reason 增加原因
     * @return bool
     */
    public static function addBalance(int $userId, float $amount, string $reason = 'win'): bool
    {
        $user = self::find($userId);
        
        if (!$user) {
            return false;
        }
        
        $user->money_balance += $amount;
        
        return $user->save() !== false;
    }

    /**
     * 检查用户状态
     * @param int $userId 用户ID
     * @return bool
     */
    public static function isUserActive(int $userId): bool
    {
        $user = self::find($userId);
        return $user && $user->status == self::STATUS_NORMAL;
    }

    /**
     * 更新用户状态
     * @param int $userId 用户ID
     * @param int $status 新状态
     * @return bool
     */
    public static function updateUserStatus(int $userId, int $status): bool
    {
        return self::where('id', $userId)
            ->update(['status' => $status, 'update_time' => time()]) !== false;
    }

    /**
     * 获取用户投注统计
     * @param int $userId 用户ID
     * @param string $period 统计周期
     * @return array
     */
    public static function getUserBetStats(int $userId, string $period = 'today'): array
    {
        // 这里可以调用投注记录模型获取统计
        return \app\model\sicbo\SicboBetRecords::getUserBetStats($userId, $period);
    }

    /**
     * 获取用户等级信息
     * @param int $userId 用户ID
     * @return array
     */
    public static function getUserLevel(int $userId): array
    {
        $user = self::find($userId);
        
        if (!$user) {
            return ['level' => 0, 'level_name' => '未知'];
        }
        
        $levelNames = [
            0 => '普通用户',
            1 => '铜牌会员',
            2 => '银牌会员',
            3 => '金牌会员',
            4 => '钻石会员',
            5 => 'VIP会员',
        ];
        
        return [
            'level' => $user->level ?? 0,
            'level_name' => $levelNames[$user->level ?? 0] ?? '普通用户'
        ];
    }

    /**
     * 创建新用户
     * @param array $userData 用户数据
     * @return bool|int
     */
    public static function createUser(array $userData)
    {
        $defaultData = [
            'money_balance' => 0,
            'frozen_amount' => 0,
            'total_recharge' => 0,
            'total_withdraw' => 0,
            'status' => self::STATUS_NORMAL,
            'level' => 0,
            'create_time' => time(),
            'update_time' => time()
        ];
        
        $userData = array_merge($defaultData, $userData);
        
        // 密码加密
        if (isset($userData['password'])) {
            $userData['password'] = md5($userData['password']);
        }
        
        return self::create($userData);
    }

    /**
     * 验证用户密码
     * @param int $userId 用户ID
     * @param string $password 密码
     * @return bool
     */
    public static function verifyPassword(int $userId, string $password): bool
    {
        $user = self::find($userId);
        
        if (!$user) {
            return false;
        }
        
        return $user->password === md5($password);
    }

    /**
     * 更新用户密码
     * @param int $userId 用户ID
     * @param string $newPassword 新密码
     * @return bool
     */
    public static function updatePassword(int $userId, string $newPassword): bool
    {
        return self::where('id', $userId)
            ->update([
                'password' => md5($newPassword),
                'update_time' => time()
            ]) !== false;
    }

    /**
     * 获取用户列表
     * @param array $conditions 查询条件
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array
     */
    public static function getUserList(array $conditions = [], int $page = 1, int $limit = 20): array
    {
        $query = self::where('status', '>=', 0); // 排除已删除用户
        
        // 应用查询条件
        if (!empty($conditions['username'])) {
            $query->where('username', 'like', '%' . $conditions['username'] . '%');
        }
        
        if (!empty($conditions['status'])) {
            $query->where('status', $conditions['status']);
        }
        
        if (!empty($conditions['level'])) {
            $query->where('level', $conditions['level']);
        }
        
        $total = $query->count();
        $users = $query->order('id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
            'users' => $users,
        ];
    }

    /**
     * 获取在线用户统计
     * @return array
     */
    public static function getOnlineStats(): array
    {
        // 这里可以结合缓存或日志获取在线用户统计
        // 简单实现：返回今日活跃用户
        $todayActive = self::whereTime('update_time', 'today')
            ->where('status', self::STATUS_NORMAL)
            ->count();
        
        return [
            'today_active' => $todayActive,
            'total_users' => self::where('status', self::STATUS_NORMAL)->count(),
        ];
    }

    /**
     * 获取状态名称
     * @param int $status 状态
     * @return string
     */
    public static function getStatusName(int $status): string
    {
        $statuses = [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_NORMAL => '正常',
            self::STATUS_FROZEN => '冻结',
        ];
        
        return $statuses[$status] ?? '未知状态';
    }
}