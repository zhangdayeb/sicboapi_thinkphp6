<?php
declare(strict_types=1);

namespace app\model\sicbo;

use think\Model;

/**
 * 骰宝赔率配置模型
 * Class SicboOdds
 * @package app\model\sicbo
 */
class SicboOdds extends Model
{
    // 数据表名
    protected $name = 'sicbo_odds';
    
    // 主键
    protected $pk = 'id';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // 数据类型转换
    protected $type = [
        'id'          => 'integer',
        'odds'        => 'float',
        'min_bet'     => 'float',
        'max_bet'     => 'float',
        'probability' => 'float',
        'house_edge'  => 'float',
        'sort_order'  => 'integer',
        'status'      => 'integer',
        'created_at'  => 'timestamp',
        'updated_at'  => 'timestamp',
    ];

    // 只读字段
    protected $readonly = [
        'id',
        'bet_type',
        'created_at',
    ];

    // 投注分类映射
    const BET_CATEGORIES = [
        'basic' => '基础投注',
        'total' => '总和投注',
        'single' => '单骰投注',
        'pair' => '对子投注',
        'triple' => '三同号投注',
        'combo' => '组合投注',
    ];

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    /**
     * 获取所有有效赔率配置
     * @return array
     */
    public static function getAllActiveOdds(): array
    {
        return self::where('status', self::STATUS_ENABLED)
            ->order('sort_order asc, id asc')
            ->select()
            ->toArray();
    }

    /**
     * 根据投注类型获取赔率配置
     * @param string $betType 投注类型
     * @return array|null
     */
    public static function getOddsByBetType(string $betType): ?array
    {
        $result = self::where('bet_type', $betType)
            ->where('status', self::STATUS_ENABLED)
            ->find();
        
        return $result ? $result->toArray() : null;
    }

    /**
     * 根据分类获取赔率配置
     * @param string $category 投注分类
     * @return array
     */
    public static function getOddsByCategory(string $category): array
    {
        return self::where('bet_category', $category)
            ->where('status', self::STATUS_ENABLED)
            ->order('sort_order asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取基础投注赔率
     * @return array
     */
    public static function getBasicOdds(): array
    {
        return self::getOddsByCategory('basic');
    }

    /**
     * 获取总和投注赔率
     * @return array
     */
    public static function getTotalOdds(): array
    {
        return self::getOddsByCategory('total');
    }

    /**
     * 获取单骰投注赔率
     * @return array
     */
    public static function getSingleOdds(): array
    {
        return self::getOddsByCategory('single');
    }

    /**
     * 获取对子投注赔率
     * @return array
     */
    public static function getPairOdds(): array
    {
        return self::getOddsByCategory('pair');
    }

    /**
     * 获取三同号投注赔率
     * @return array
     */
    public static function getTripleOdds(): array
    {
        return self::getOddsByCategory('triple');
    }

    /**
     * 获取组合投注赔率
     * @return array
     */
    public static function getComboOdds(): array
    {
        return self::getOddsByCategory('combo');
    }

    /**
     * 批量获取指定投注类型的赔率
     * @param array $betTypes 投注类型数组
     * @return array
     */
    public static function getBatchOdds(array $betTypes): array
    {
        $results = self::whereIn('bet_type', $betTypes)
            ->where('status', self::STATUS_ENABLED)
            ->select()
            ->toArray();
        
        $odds = [];
        foreach ($results as $result) {
            $odds[$result['bet_type']] = $result;
        }
        
        return $odds;
    }

    /**
     * 更新赔率配置
     * @param string $betType 投注类型
     * @param array $data 更新数据
     * @return bool
     */
    public static function updateOdds(string $betType, array $data): bool
    {
        return self::where('bet_type', $betType)->update($data) !== false;
    }

    /**
     * 启用/禁用投注类型
     * @param string $betType 投注类型
     * @param int $status 状态
     * @return bool
     */
    public static function toggleStatus(string $betType, int $status): bool
    {
        return self::where('bet_type', $betType)
            ->update(['status' => $status]) !== false;
    }

    /**
     * 批量启用/禁用投注类型
     * @param array $betTypes 投注类型数组
     * @param int $status 状态
     * @return bool
     */
    public static function batchToggleStatus(array $betTypes, int $status): bool
    {
        return self::whereIn('bet_type', $betTypes)
            ->update(['status' => $status]) !== false;
    }

    /**
     * 获取投注类型的限额信息
     * @param string $betType 投注类型
     * @return array|null
     */
    public static function getBetLimits(string $betType): ?array
    {
        $result = self::where('bet_type', $betType)
            ->where('status', self::STATUS_ENABLED)
            ->field('min_bet,max_bet')
            ->find();
        
        return $result ? $result->toArray() : null;
    }

    /**
     * 验证投注金额是否在限额范围内
     * @param string $betType 投注类型
     * @param float $amount 投注金额
     * @return bool
     */
    public static function validateBetAmount(string $betType, float $amount): bool
    {
        $limits = self::getBetLimits($betType);
        
        if (!$limits) {
            return false;
        }
        
        return $amount >= $limits['min_bet'] && $amount <= $limits['max_bet'];
    }

    /**
     * 获取所有投注类型列表
     * @param bool $activeOnly 是否只获取启用的
     * @return array
     */
    public static function getAllBetTypes(bool $activeOnly = true): array
    {
        $query = self::field('bet_type,bet_name_cn,bet_category,status');
        
        if ($activeOnly) {
            $query->where('status', self::STATUS_ENABLED);
        }
        
        return $query->order('sort_order asc')->select()->toArray();
    }

    /**
     * 根据投注类型获取中文名称
     * @param string $betType 投注类型
     * @return string
     */
    public static function getBetTypeName(string $betType): string
    {
        $result = self::where('bet_type', $betType)->value('bet_name_cn');
        return $result ?: $betType;
    }

    /**
     * 计算理论返还率
     * @param string $betType 投注类型
     * @return float
     */
    public static function calculateRTP(string $betType): float
    {
        $odds = self::getOddsByBetType($betType);
        
        if (!$odds || !$odds['probability']) {
            return 0.0;
        }
        
        return ($odds['odds'] + 1) * $odds['probability'];
    }

    /**
     * 获取庄家优势最低的投注类型
     * @param int $limit 获取数量
     * @return array
     */
    public static function getBestOdds(int $limit = 5): array
    {
        return self::where('status', self::STATUS_ENABLED)
            ->where('house_edge', '>', 0)
            ->order('house_edge asc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取分类统计
     * @return array
     */
    public static function getCategoryStats(): array
    {
        $results = self::field('bet_category, count(*) as count, avg(house_edge) as avg_house_edge')
            ->where('status', self::STATUS_ENABLED)
            ->group('bet_category')
            ->select()
            ->toArray();
        
        $stats = [];
        foreach ($results as $result) {
            $stats[$result['bet_category']] = [
                'count' => $result['count'],
                'avg_house_edge' => round($result['avg_house_edge'], 4),
                'name' => self::BET_CATEGORIES[$result['bet_category']] ?? $result['bet_category'],
            ];
        }
        
        return $stats;
    }

    /**
     * 搜索投注类型
     * @param string $keyword 关键词
     * @return array
     */
    public static function searchBetTypes(string $keyword): array
    {
        return self::where('bet_name_cn', 'like', "%{$keyword}%")
            ->whereOr('bet_name_en', 'like', "%{$keyword}%")
            ->whereOr('bet_type', 'like', "%{$keyword}%")
            ->where('status', self::STATUS_ENABLED)
            ->order('sort_order asc')
            ->select()
            ->toArray();
    }

    /**
     * 创建新的赔率配置
     * @param array $data 配置数据
     * @return bool|int
     */
    public static function createOdds(array $data)
    {
        // 设置默认排序值
        if (!isset($data['sort_order'])) {
            $maxSort = self::max('sort_order') ?: 0;
            $data['sort_order'] = $maxSort + 1;
        }
        
        // 设置默认状态
        if (!isset($data['status'])) {
            $data['status'] = self::STATUS_ENABLED;
        }
        
        return self::create($data);
    }

    /**
     * 检查投注类型是否存在
     * @param string $betType 投注类型
     * @return bool
     */
    public static function betTypeExists(string $betType): bool
    {
        return self::where('bet_type', $betType)->count() > 0;
    }

    /**
     * 获取赔率配置的缓存数据
     * @return array
     */
    public static function getCachedOdds(): array
    {
        $cacheKey = 'sicbo:odds:all';
        $odds = cache($cacheKey);
        
        if ($odds === false) {
            $odds = self::getAllActiveOdds();
            cache($cacheKey, $odds, 3600); // 缓存1小时
        }
        
        return $odds;
    }

    /**
     * 清除赔率配置缓存
     * @return bool
     */
    public static function clearOddsCache(): bool
    {
        $cacheKeys = [
            'sicbo:odds:all',
            'sicbo:odds:basic',
            'sicbo:odds:total',
            'sicbo:odds:single',
            'sicbo:odds:pair',
            'sicbo:odds:triple',
            'sicbo:odds:combo',
        ];
        
        foreach ($cacheKeys as $key) {
            cache($key, null);
        }
        
        return true;
    }

    /**
     * 导出赔率配置
     * @return array
     */
    public static function exportOdds(): array
    {
        return self::order('sort_order asc')
            ->select()
            ->toArray();
    }

    /**
     * 导入赔率配置
     * @param array $odds 赔率数据
     * @return bool
     */
    public static function importOdds(array $odds): bool
    {
        try {
            self::startTrans();
            
            foreach ($odds as $odd) {
                if (self::betTypeExists($odd['bet_type'])) {
                    self::where('bet_type', $odd['bet_type'])->update($odd);
                } else {
                    self::create($odd);
                }
            }
            
            self::commit();
            self::clearOddsCache();
            
            return true;
        } catch (\Exception $e) {
            self::rollback();
            return false;
        }
    }
}