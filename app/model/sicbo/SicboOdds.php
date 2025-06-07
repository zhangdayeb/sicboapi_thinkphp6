<?php

namespace app\model\sicbo;

use think\Model;
use think\facade\Db;
use think\facade\Cache;

/**
 * 骰宝赔率配置模型 - 完整版
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
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
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

    // 缓存前缀
    const CACHE_PREFIX = 'sicbo_odds:';

    /**
     * ========================================
     * 基础查询方法
     * ========================================
     */

    /**
     * 获取所有有效赔率配置
     * @return array
     */
    public static function getAllActiveOdds(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'all_active';
        $odds = Cache::get($cacheKey);
        
        if ($odds === false) {
            $odds = self::where('status', self::STATUS_ENABLED)
                ->order('sort_order asc, id asc')
                ->select()
                ->toArray();
            Cache::set($cacheKey, $odds, 3600); // 缓存1小时
        }
        
        return $odds;
    }

    /**
     * 根据投注类型获取赔率配置
     * @param string $betType 投注类型
     * @return array|null
     */
    public static function getOddsByBetType(string $betType): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'type:' . $betType;
        $odds = Cache::get($cacheKey);
        
        if ($odds === false) {
            $result = self::where('bet_type', $betType)
                ->where('status', self::STATUS_ENABLED)
                ->find();
            
            $odds = $result ? $result->toArray() : null;
            Cache::set($cacheKey, $odds, 3600);
        }
        
        return $odds;
    }

    /**
     * 根据分类获取赔率配置
     * @param string $category 投注分类
     * @return array
     */
    public static function getOddsByCategory(string $category): array
    {
        $cacheKey = self::CACHE_PREFIX . 'category:' . $category;
        $odds = Cache::get($cacheKey);
        
        if ($odds === false) {
            $odds = self::where('bet_category', $category)
                ->where('status', self::STATUS_ENABLED)
                ->order('sort_order asc')
                ->select()
                ->toArray();
            Cache::set($cacheKey, $odds, 3600);
        }
        
        return $odds;
    }

    /**
     * ========================================
     * 分类查询方法
     * ========================================
     */

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
     * ========================================
     * 批量操作方法
     * ========================================
     */

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
     * 批量启用/禁用投注类型
     * @param array $betTypes 投注类型数组
     * @param int $status 状态
     * @return bool
     */
    public static function batchToggleStatus(array $betTypes, int $status): bool
    {
        $result = self::whereIn('bet_type', $betTypes)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        
        if ($result !== false) {
            self::clearOddsCache();
            return true;
        }
        
        return false;
    }

    /**
     * ========================================
     * 数据操作方法
     * ========================================
     */

    /**
     * 更新赔率配置
     * @param string $betType 投注类型
     * @param array $data 更新数据
     * @return bool
     */
    public static function updateOdds(string $betType, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $result = self::where('bet_type', $betType)->update($data);
        
        if ($result !== false) {
            self::clearOddsCache();
            return true;
        }
        
        return false;
    }

    /**
     * 启用/禁用投注类型
     * @param string $betType 投注类型
     * @param int $status 状态
     * @return bool
     */
    public static function toggleStatus(string $betType, int $status): bool
    {
        return self::updateOdds($betType, ['status' => $status]);
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
        
        $result = self::create($data);
        
        if ($result) {
            self::clearOddsCache();
        }
        
        return $result;
    }

    /**
     * ========================================
     * 限额和验证方法
     * ========================================
     */

    /**
     * 获取投注类型的限额信息
     * @param string $betType 投注类型
     * @return array|null
     */
    public static function getBetLimits(string $betType): ?array
    {
        $odds = self::getOddsByBetType($betType);
        
        if (!$odds) {
            return null;
        }
        
        return [
            'min_bet' => $odds['min_bet'],
            'max_bet' => $odds['max_bet']
        ];
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
     * 批量验证投注金额
     * @param array $bets 投注数组 [['bet_type' => 'big', 'amount' => 100]]
     * @return array 验证结果
     */
    public static function validateBatchBets(array $bets): array
    {
        $result = [
            'valid' => true,
            'errors' => [],
            'details' => []
        ];
        
        foreach ($bets as $index => $bet) {
            $betType = $bet['bet_type'] ?? '';
            $amount = $bet['amount'] ?? 0;
            
            if (empty($betType)) {
                $result['valid'] = false;
                $result['errors'][] = "投注 {$index}: 投注类型不能为空";
                continue;
            }
            
            if ($amount <= 0) {
                $result['valid'] = false;
                $result['errors'][] = "投注 {$index}: 投注金额必须大于0";
                continue;
            }
            
            $odds = self::getOddsByBetType($betType);
            if (!$odds) {
                $result['valid'] = false;
                $result['errors'][] = "投注 {$index}: 投注类型 {$betType} 不存在或已禁用";
                continue;
            }
            
            if (!self::validateBetAmount($betType, $amount)) {
                $result['valid'] = false;
                $result['errors'][] = "投注 {$index}: 投注金额 {$amount} 超出限额范围 ({$odds['min_bet']} - {$odds['max_bet']})";
                continue;
            }
            
            $result['details'][] = [
                'bet_type' => $betType,
                'amount' => $amount,
                'odds' => $odds['odds'],
                'potential_win' => $amount * $odds['odds'],
                'valid' => true
            ];
        }
        
        return $result;
    }

    /**
     * ========================================
     * 查询和统计方法
     * ========================================
     */

    /**
     * 获取所有投注类型列表
     * @param bool $activeOnly 是否只获取启用的
     * @return array
     */
    public static function getAllBetTypes(bool $activeOnly = true): array
    {
        $query = self::field('bet_type,bet_name_cn,bet_name_en,bet_category,odds,status');
        
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
        $cacheKey = self::CACHE_PREFIX . 'name:' . $betType;
        $name = Cache::get($cacheKey);
        
        if ($name === false) {
            $name = self::where('bet_type', $betType)->value('bet_name_cn') ?: $betType;
            Cache::set($cacheKey, $name, 3600);
        }
        
        return $name;
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
     * 获取分类统计
     * @return array
     */
    public static function getCategoryStats(): array
    {
        $results = self::field('bet_category, count(*) as count, avg(odds) as avg_odds, avg(house_edge) as avg_house_edge')
            ->where('status', self::STATUS_ENABLED)
            ->group('bet_category')
            ->select()
            ->toArray();
        
        $stats = [];
        foreach ($results as $result) {
            $stats[$result['bet_category']] = [
                'count' => $result['count'],
                'avg_odds' => round($result['avg_odds'], 2),
                'avg_house_edge' => round($result['avg_house_edge'], 4),
                'name' => self::BET_CATEGORIES[$result['bet_category']] ?? $result['bet_category'],
            ];
        }
        
        return $stats;
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
     * ========================================
     * 计算和分析方法
     * ========================================
     */

    /**
     * 计算理论返还率(RTP)
     * @param string $betType 投注类型
     * @return float
     */
    public static function calculateRTP(string $betType): float
    {
        $odds = self::getOddsByBetType($betType);
        
        if (!$odds || !$odds['probability'] || $odds['probability'] <= 0) {
            return 0.0;
        }
        
        return round(($odds['odds'] + 1) * $odds['probability'], 4);
    }

    /**
     * 计算庄家优势
     * @param string $betType 投注类型
     * @return float
     */
    public static function calculateHouseEdge(string $betType): float
    {
        $rtp = self::calculateRTP($betType);
        return round((1 - $rtp) * 100, 4);
    }

    /**
     * 批量计算所有投注类型的RTP和庄家优势
     * @return array
     */
    public static function calculateAllRTP(): array
    {
        $allOdds = self::getAllActiveOdds();
        $results = [];
        
        foreach ($allOdds as $odds) {
            $rtp = self::calculateRTP($odds['bet_type']);
            $houseEdge = self::calculateHouseEdge($odds['bet_type']);
            
            $results[] = [
                'bet_type' => $odds['bet_type'],
                'bet_name' => $odds['bet_name_cn'],
                'odds' => $odds['odds'],
                'probability' => $odds['probability'],
                'rtp' => $rtp,
                'house_edge' => $houseEdge,
                'category' => $odds['bet_category']
            ];
        }
        
        return $results;
    }

    /**
     * ========================================
     * 工具方法
     * ========================================
     */

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
        return self::getAllActiveOdds();
    }

    /**
     * 清除赔率配置缓存
     * @return bool
     */
    public static function clearOddsCache(): bool
    {
        $cacheKeys = [
            self::CACHE_PREFIX . 'all_active',
            self::CACHE_PREFIX . 'category:basic',
            self::CACHE_PREFIX . 'category:total',
            self::CACHE_PREFIX . 'category:single',
            self::CACHE_PREFIX . 'category:pair',
            self::CACHE_PREFIX . 'category:triple',
            self::CACHE_PREFIX . 'category:combo',
        ];
        
        foreach ($cacheKeys as $key) {
            Cache::delete($key);
        }
        
        // 清除类型相关的缓存（使用标签清除）
        Cache::tag(self::CACHE_PREFIX . 'type')->clear();
        Cache::tag(self::CACHE_PREFIX . 'name')->clear();
        
        return true;
    }

    /**
     * ========================================
     * 导入导出方法
     * ========================================
     */

    /**
     * 导出赔率配置
     * @param string $format 导出格式 json/array
     * @return array|string
     */
    public static function exportOdds(string $format = 'array')
    {
        $data = self::order('sort_order asc')
            ->select()
            ->toArray();
        
        if ($format === 'json') {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        
        return $data;
    }

    /**
     * 导入赔率配置
     * @param array $odds 赔率数据
     * @param bool $overwrite 是否覆盖已存在的数据
     * @return bool
     */
    public static function importOdds(array $odds, bool $overwrite = false): bool
    {
        try {
            Db::startTrans();
            
            foreach ($odds as $odd) {
                if (empty($odd['bet_type'])) {
                    continue; // 跳过无效数据
                }
                
                $exists = self::betTypeExists($odd['bet_type']);
                
                if ($exists && $overwrite) {
                    // 更新已存在的记录
                    self::where('bet_type', $odd['bet_type'])->update($odd);
                } elseif (!$exists) {
                    // 创建新记录
                    self::create($odd);
                }
            }
            
            Db::commit();
            self::clearOddsCache();
            
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }

    /**
     * ========================================
     * 管理方法
     * ========================================
     */

    /**
     * 获取管理后台用的完整赔率列表
     * @param array $filters 筛选条件
     * @return array
     */
    public static function getAdminOddsList(array $filters = []): array
    {
        $query = self::alias('o');
        
        // 应用筛选条件
        if (!empty($filters['bet_category'])) {
            $query->where('o.bet_category', $filters['bet_category']);
        }
        
        if (!empty($filters['status'])) {
            $query->where('o.status', $filters['status']);
        }
        
        if (!empty($filters['keyword'])) {
            $query->where('o.bet_name_cn', 'like', "%{$filters['keyword']}%")
                  ->whereOr('o.bet_name_en', 'like', "%{$filters['keyword']}%")
                  ->whereOr('o.bet_type', 'like', "%{$filters['keyword']}%");
        }
        
        return $query->order('o.bet_category asc, o.sort_order asc')
            ->select()
            ->toArray();
    }

    /**
     * 重新排序赔率配置
     * @param array $sortData 排序数据 [['bet_type' => 'big', 'sort_order' => 1]]
     * @return bool
     */
    public static function reorderOdds(array $sortData): bool
    {
        try {
            Db::startTrans();
            
            foreach ($sortData as $item) {
                if (empty($item['bet_type']) || !isset($item['sort_order'])) {
                    continue;
                }
                
                self::where('bet_type', $item['bet_type'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
            
            Db::commit();
            self::clearOddsCache();
            
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }

    /**
     * 恢复默认赔率配置
     * @return bool
     */
    public static function restoreDefaultOdds(): bool
    {
        $defaultOdds = self::getDefaultOddsConfig();
        return self::importOdds($defaultOdds, true);
    }

    /**
     * 获取默认赔率配置
     * @return array
     */
    private static function getDefaultOddsConfig(): array
    {
        return [
            // 基础投注
            [
                'bet_type' => 'big',
                'bet_name_cn' => '大',
                'bet_name_en' => 'Big',
                'bet_category' => 'basic',
                'odds' => 1.0,
                'min_bet' => 10,
                'max_bet' => 50000,
                'probability' => 0.4861,
                'house_edge' => 0.0278,
                'sort_order' => 1,
                'status' => 1
            ],
            [
                'bet_type' => 'small',
                'bet_name_cn' => '小',
                'bet_name_en' => 'Small',
                'bet_category' => 'basic',
                'odds' => 1.0,
                'min_bet' => 10,
                'max_bet' => 50000,
                'probability' => 0.4861,
                'house_edge' => 0.0278,
                'sort_order' => 2,
                'status' => 1
            ],
            [
                'bet_type' => 'odd',
                'bet_name_cn' => '单',
                'bet_name_en' => 'Odd',
                'bet_category' => 'basic',
                'odds' => 1.0,
                'min_bet' => 10,
                'max_bet' => 50000,
                'probability' => 0.5,
                'house_edge' => 0.0,
                'sort_order' => 3,
                'status' => 1
            ],
            [
                'bet_type' => 'even',
                'bet_name_cn' => '双',
                'bet_name_en' => 'Even',
                'bet_category' => 'basic',
                'odds' => 1.0,
                'min_bet' => 10,
                'max_bet' => 50000,
                'probability' => 0.5,
                'house_edge' => 0.0,
                'sort_order' => 4,
                'status' => 1
            ],
            // 总和投注
            [
                'bet_type' => 'total-4',
                'bet_name_cn' => '总和4',
                'bet_name_en' => 'Total 4',
                'bet_category' => 'total',
                'odds' => 60.0,
                'min_bet' => 10,
                'max_bet' => 1000,
                'probability' => 0.0139,
                'house_edge' => 0.1528,
                'sort_order' => 11,
                'status' => 1
            ],
            [
                'bet_type' => 'total-17',
                'bet_name_cn' => '总和17',
                'bet_name_en' => 'Total 17',
                'bet_category' => 'total',
                'odds' => 60.0,
                'min_bet' => 10,
                'max_bet' => 1000,
                'probability' => 0.0139,
                'house_edge' => 0.1528,
                'sort_order' => 25,
                'status' => 1
            ],
            // 可以继续添加更多默认配置...
        ];
    }
}