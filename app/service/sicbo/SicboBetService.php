<?php


namespace app\service\sicbo;

use app\controller\common\LogHelper;
use app\model\sicbo\SicboBetRecords;
use app\model\sicbo\SicboOdds;
use app\model\sicbo\SicboGameResults;
use app\model\UserModel;
use app\model\MoneyLog;
use app\model\Table;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Lock;

/**
 * ========================================
 * 骰宝投注处理服务类
 * ========================================
 * 
 * 功能概述：
 * - 用户投注下单和验证
 * - 投注限额和风控检查
 * - 用户余额管理和资金安全
 * - 投注记录管理和查询
 * - 重复投注和修改投注处理
 * - 投注统计和分析
 * - 异常投注处理和回滚
 * 
 * 投注流程：
 * 1. 投注验证 -> 余额检查 -> 限额验证 -> 风控检查
 * 2. 资金冻结 -> 创建记录 -> 更新余额 -> 记录日志
 * 3. 支持修改、取消、查询等操作
 * 4. 完整的事务安全和并发控制
 * 
 * @package app\service\sicbo
 * @author  系统开发团队
 * @version 1.0
 */
class SicboBetService
{
    /**
     * 投注状态常量
     */
    const BET_STATUS_PENDING = 0;     // 待结算
    const BET_STATUS_SETTLED = 1;     // 已结算
    const BET_STATUS_CANCELLED = 2;   // 已取消
    const BET_STATUS_FAILED = 3;      // 失败

    /**
     * 资金操作类型
     */
    const MONEY_TYPE_BET = 501;         // 投注扣款
    const MONEY_TYPE_REFUND = 502;      // 退款
    const MONEY_TYPE_MODIFY = 503;      // 修改投注

    /**
     * 风控限制类型
     */
    const RISK_TYPE_AMOUNT = 'amount';     // 金额限制
    const RISK_TYPE_FREQUENCY = 'frequency'; // 频率限制
    const RISK_TYPE_PATTERN = 'pattern';   // 模式限制

    /**
     * 缓存键前缀
     */
    const CACHE_PREFIX_USER_BET = 'sicbo:user_bet:';
    const CACHE_PREFIX_GAME_BET = 'sicbo:game_bet:';
    const CACHE_PREFIX_LIMIT = 'sicbo:limit:';

    /**
     * 服务依赖
     */
    private SicboCalculationService $calculationService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->calculationService = new SicboCalculationService();
    }

    /**
     * ========================================
     * 核心投注处理方法
     * ========================================
     */

    /**
     * 处理用户投注
     * 
     * @param int $userId 用户ID
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @param array $bets 投注数据
     * @param array $options 附加选项
     * @return array 投注结果
     */
    public function placeBet(int $userId, int $tableId, string $gameNumber, array $bets, array $options = []): array
    {
        $startTime = microtime(true);
        
        LogHelper::debug('=== 处理用户投注 ===', [
            'user_id' => $userId,
            'table_id' => $tableId,
            'game_number' => $gameNumber,
            'bet_count' => count($bets),
            'options' => $options
        ]);

        // 使用分布式锁防止并发投注
        $lockKey = "sicbo:bet_lock:{$userId}:{$gameNumber}";
        $lock = Lock::store('redis')->get($lockKey, 10);

        if (!$lock) {
            throw new \Exception('投注处理中，请稍后重试');
        }

        try {
            // 1. 基础验证
            $this->validateBasicBetData($userId, $tableId, $gameNumber, $bets);
            
            // 2. 游戏状态验证
            $gameSession = $this->validateGameSession($tableId, $gameNumber);
            
            // 3. 用户状态验证
            $user = $this->validateUserStatus($userId);
            
            // 4. 投注数据验证和处理
            $processedBets = $this->processBetData($bets);
            
            // 5. 计算投注总金额
            $totalAmount = $this->calculateTotalAmount($processedBets);
            
            // 6. 限额验证
            $this->validateBetLimits($userId, $tableId, $processedBets, $totalAmount);
            
            // 7. 余额验证
            $this->validateUserBalance($user, $totalAmount);
            
            // 8. 风控检查
            $this->performRiskControl($userId, $tableId, $processedBets, $totalAmount);
            
            // 9. 处理重复投注
            $previousBetAmount = $this->handlePreviousBets($userId, $gameNumber);
            
            // 10. 执行投注事务
            $betResult = $this->executeBetTransaction($userId, $tableId, $gameNumber, $processedBets, $totalAmount, $previousBetAmount, $gameSession);
            
            // 11. 后续处理
            $this->postBetProcessing($userId, $tableId, $gameNumber, $betResult);

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            LogHelper::debug('=== 用户投注完成 ===', [
                'user_id' => $userId,
                'game_number' => $gameNumber,
                'total_amount' => $totalAmount,
                'bet_count' => count($processedBets),
                'duration_ms' => $duration
            ]);

            return [
                'success' => true,
                'game_number' => $gameNumber,
                'bet_count' => count($processedBets),
                'total_amount' => $totalAmount,
                'previous_refund' => $previousBetAmount,
                'current_balance' => $betResult['new_balance'],
                'bet_ids' => $betResult['bet_ids'],
                'processing_time' => $duration
            ];

        } catch (\Exception $e) {
            LogHelper::error('用户投注失败', [
                'user_id' => $userId,
                'game_number' => $gameNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * 修改当前投注
     * 
     * @param int $userId 用户ID
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @param array $newBets 新的投注数据
     * @return array 修改结果
     */
    public function modifyBet(int $userId, int $tableId, string $gameNumber, array $newBets): array
    {
        LogHelper::debug('修改用户投注', [
            'user_id' => $userId,
            'game_number' => $gameNumber,
            'new_bet_count' => count($newBets)
        ]);

        // 验证修改时机
        $gameSession = $this->validateGameSession($tableId, $gameNumber);
        if ($gameSession['status'] !== 'betting') {
            throw new \Exception('当前游戏状态不允许修改投注');
        }

        // 检查修改时间限制（投注结束前30秒不允许修改）
        $timeLeft = $gameSession['betting_end_time'] - time();
        if ($timeLeft < 30) {
            throw new \Exception('投注即将结束，无法修改');
        }

        // 获取现有投注
        $existingBets = $this->getCurrentUserBets($userId, $gameNumber);
        if (empty($existingBets)) {
            throw new \Exception('当前局无投注记录');
        }

        // 修改投注实际上是取消旧投注并创建新投注
        return $this->placeBet($userId, $tableId, $gameNumber, $newBets, ['is_modify' => true]);
    }

    /**
     * 取消当前投注
     * 
     * @param int $userId 用户ID
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @param string $reason 取消原因
     * @return array 取消结果
     */
    public function cancelBet(int $userId, int $tableId, string $gameNumber, string $reason = ''): array
    {
        LogHelper::debug('取消用户投注', [
            'user_id' => $userId,
            'game_number' => $gameNumber,
            'reason' => $reason
        ]);

        try {
            // 验证取消时机
            $gameSession = $this->validateGameSession($tableId, $gameNumber);
            if ($gameSession['status'] !== 'betting') {
                throw new \Exception('当前游戏状态不允许取消投注');
            }

            // 检查取消时间限制（投注结束前10秒不允许取消）
            $timeLeft = $gameSession['betting_end_time'] - time();
            if ($timeLeft < 10) {
                throw new \Exception('投注即将结束，无法取消');
            }

            // 获取待取消的投注
            $existingBets = $this->getCurrentUserBets($userId, $gameNumber);
            if (empty($existingBets)) {
                throw new \Exception('当前局无投注记录');
            }

            $refundAmount = array_sum(array_column($existingBets, 'bet_amount'));

            // 执行取消事务
            Db::startTrans();
            
            try {
                // 1. 更新投注状态为已取消
                SicboBetRecords::where('user_id', $userId)
                    ->where('game_number', $gameNumber)
                    ->where('settle_status', self::BET_STATUS_PENDING)
                    ->update([
                        'settle_status' => self::BET_STATUS_CANCELLED,
                        'cancel_reason' => $reason,
                        'settle_time' => date('Y-m-d H:i:s')
                    ]);

                // 2. 退还用户余额
                $user = UserModel::where('id', $userId)->lock(true)->find();
                $oldBalance = $user->money_balance;
                $newBalance = $oldBalance + $refundAmount;
                
                $user->money_balance = $newBalance;
                $user->save();

                // 3. 记录资金日志
                $this->createMoneyLog($userId, self::MONEY_TYPE_REFUND, $refundAmount, $oldBalance, $newBalance, "投注取消退款: {$reason}");

                Db::commit();

                // 4. 清除相关缓存
                $this->clearUserBetCache($userId, $gameNumber);

                LogHelper::debug('投注取消成功', [
                    'user_id' => $userId,
                    'game_number' => $gameNumber,
                    'refund_amount' => $refundAmount,
                    'new_balance' => $newBalance
                ]);

                return [
                    'success' => true,
                    'game_number' => $gameNumber,
                    'refund_amount' => $refundAmount,
                    'current_balance' => $newBalance,
                    'cancelled_bets' => count($existingBets)
                ];

            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            LogHelper::error('取消投注失败', [
                'user_id' => $userId,
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * ========================================
     * 投注查询和统计方法
     * ========================================
     */

    /**
     * 获取用户当前投注
     * 
     * @param int $userId 用户ID
     * @param string $gameNumber 游戏局号
     * @return array 当前投注记录
     */
    public function getCurrentUserBets(int $userId, string $gameNumber): array
    {
        try {
            // 先从缓存获取
            $cacheKey = self::CACHE_PREFIX_USER_BET . "{$userId}:{$gameNumber}";
            $cachedBets = Cache::get($cacheKey);
            
            if ($cachedBets !== null) {
                return $cachedBets;
            }

            // 从数据库查询
            $bets = SicboBetRecords::where('user_id', $userId)
                ->where('game_number', $gameNumber)
                ->where('settle_status', self::BET_STATUS_PENDING)
                ->order('id asc')
                ->select()
                ->toArray();

            // 格式化数据
            $formattedBets = [];
            $totalAmount = 0;
            
            foreach ($bets as $bet) {
                $formattedBets[] = [
                    'id' => $bet['id'],
                    'bet_type' => $bet['bet_type'],
                    'bet_type_name' => SicboOdds::getBetTypeName($bet['bet_type']),
                    'bet_amount' => $bet['bet_amount'],
                    'odds' => $bet['odds'],
                    'potential_win' => $bet['bet_amount'] * $bet['odds'],
                    'bet_time' => $bet['bet_time']
                ];
                $totalAmount += $bet['bet_amount'];
            }

            $result = [
                'bets' => $formattedBets,
                'total_amount' => $totalAmount,
                'bet_count' => count($formattedBets)
            ];

            // 缓存5分钟
            Cache::set($cacheKey, $result, 300);

            return $result;

        } catch (\Exception $e) {
            LogHelper::error('获取用户当前投注失败', [
                'user_id' => $userId,
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
            
            return ['bets' => [], 'total_amount' => 0, 'bet_count' => 0];
        }
    }

    /**
     * 获取用户投注历史
     * 
     * @param int $userId 用户ID
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array 投注历史
     */
    public function getUserBetHistory(int $userId, array $filters = [], int $page = 1, int $limit = 20): array
    {
        try {
            $query = SicboBetRecords::alias('br')
                ->leftJoin('sicbo_game_results gr', 'br.game_number = gr.game_number')
                ->where('br.user_id', $userId)
                ->order('br.bet_time desc');

            // 应用筛选条件
            if (!empty($filters['table_id'])) {
                $query->where('br.table_id', $filters['table_id']);
            }
            
            if (!empty($filters['bet_type'])) {
                $query->where('br.bet_type', $filters['bet_type']);
            }
            
            if (!empty($filters['is_win'])) {
                $query->where('br.is_win', $filters['is_win']);
            }
            
            if (!empty($filters['start_date'])) {
                $query->where('br.bet_time', '>=', $filters['start_date'] . ' 00:00:00');
            }
            
            if (!empty($filters['end_date'])) {
                $query->where('br.bet_time', '<=', $filters['end_date'] . ' 23:59:59');
            }

            // 分页查询
            $offset = ($page - 1) * $limit;
            $total = $query->count();
            
            $records = $query->field([
                'br.id',
                'br.game_number',
                'br.table_id',
                'br.round_number',
                'br.bet_type',
                'br.bet_amount',
                'br.odds',
                'br.is_win',
                'br.win_amount',
                'br.bet_time',
                'br.settle_time',
                'br.settle_status',
                'gr.dice1',
                'gr.dice2',
                'gr.dice3',
                'gr.total_points',
                'gr.is_big',
                'gr.is_odd'
            ])
            ->limit($offset, $limit)
            ->select()
            ->toArray();

            // 计算统计数据
            $stats = $this->calculateUserBetStatistics($userId, $filters);

            return [
                'records' => $records,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ],
                'statistics' => $stats
            ];

        } catch (\Exception $e) {
            LogHelper::error('获取用户投注历史失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * 获取游戏投注统计
     * 
     * @param string $gameNumber 游戏局号
     * @return array 投注统计
     */
    public function getGameBetStatistics(string $gameNumber): array
    {
        try {
            // 先从缓存获取
            $cacheKey = self::CACHE_PREFIX_GAME_BET . $gameNumber;
            $cachedStats = Cache::get($cacheKey);
            
            if ($cachedStats !== null) {
                return $cachedStats;
            }

            // 按投注类型统计
            $typeStats = SicboBetRecords::where('game_number', $gameNumber)
                ->field([
                    'bet_type',
                    'count(*) as bet_count',
                    'sum(bet_amount) as total_amount',
                    'count(distinct user_id) as user_count'
                ])
                ->group('bet_type')
                ->select()
                ->toArray();

            // 总体统计
            $totalStats = SicboBetRecords::where('game_number', $gameNumber)
                ->field([
                    'count(*) as total_bets',
                    'sum(bet_amount) as total_amount',
                    'count(distinct user_id) as total_users',
                    'avg(bet_amount) as avg_amount',
                    'max(bet_amount) as max_amount',
                    'min(bet_amount) as min_amount'
                ])
                ->find();

            $result = [
                'game_number' => $gameNumber,
                'by_type' => $typeStats,
                'total' => $totalStats ? $totalStats->toArray() : [],
                'generated_at' => time()
            ];

            // 缓存5分钟
            Cache::set($cacheKey, $result, 300);

            return $result;

        } catch (\Exception $e) {
            LogHelper::error('获取游戏投注统计失败', [
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
            
            return [
                'game_number' => $gameNumber,
                'by_type' => [],
                'total' => [],
                'generated_at' => time()
            ];
        }
    }

    /**
     * ========================================
     * 投注验证和风控方法
     * ========================================
     */

    /**
     * 验证投注基础数据
     */
    private function validateBasicBetData(int $userId, int $tableId, string $gameNumber, array $bets): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('用户ID无效');
        }
        
        if ($tableId <= 0) {
            throw new \InvalidArgumentException('台桌ID无效');
        }
        
        if (empty($gameNumber)) {
            throw new \InvalidArgumentException('游戏局号不能为空');
        }
        
        if (empty($bets) || !is_array($bets)) {
            throw new \InvalidArgumentException('投注数据不能为空');
        }
        
        if (count($bets) > 20) {
            throw new \InvalidArgumentException('单次投注类型不能超过20种');
        }
    }

    /**
     * 验证游戏会话状态
     */
    private function validateGameSession(int $tableId, string $gameNumber): array
    {
        // 获取当前游戏会话
        $gameSession = Cache::get("sicbo:game:{$tableId}");
        
        if (!$gameSession) {
            throw new \Exception('游戏会话不存在');
        }
        
        if ($gameSession['game_number'] !== $gameNumber) {
            throw new \Exception('游戏局号不匹配');
        }
        
        if ($gameSession['status'] !== 'betting') {
            throw new \Exception('当前不在投注时间');
        }
        
        // 检查投注时间
        if (time() > $gameSession['betting_end_time']) {
            throw new \Exception('投注时间已结束');
        }

        return $gameSession;
    }

    /**
     * 验证用户状态
     */
    private function validateUserStatus(int $userId): UserModel
    {
        $user = UserModel::find($userId);
        
        if (!$user) {
            throw new \Exception('用户不存在');
        }
        
        if ($user->status != 1) {
            throw new \Exception('用户账户已被禁用');
        }
        
        // 检查用户是否在黑名单
        if ($this->isUserBlacklisted($userId)) {
            throw new \Exception('用户被限制投注');
        }

        return $user;
    }

    /**
     * 处理投注数据
     */
    private function processBetData(array $bets): array
    {
        $processedBets = [];
        $betTypes = [];

        foreach ($bets as $bet) {
            if (!isset($bet['bet_type']) || !isset($bet['bet_amount'])) {
                throw new \InvalidArgumentException('投注数据格式错误');
            }

            $betType = trim($bet['bet_type']);
            $betAmount = floatval($bet['bet_amount']);

            // 验证投注类型
            $odds = SicboOdds::getOddsByBetType($betType);
            if (!$odds) {
                throw new \InvalidArgumentException("无效的投注类型: {$betType}");
            }

            // 验证投注金额
            if ($betAmount <= 0) {
                throw new \InvalidArgumentException("投注金额必须大于0");
            }

            if ($betAmount < $odds['min_bet'] || $betAmount > $odds['max_bet']) {
                throw new \InvalidArgumentException("投注类型 {$betType} 金额超出限额范围");
            }

            // 检查重复投注类型
            if (in_array($betType, $betTypes)) {
                throw new \InvalidArgumentException("不能对同一投注类型重复投注");
            }
            
            $betTypes[] = $betType;

            $processedBets[] = [
                'bet_type' => $betType,
                'bet_amount' => $betAmount,
                'odds' => $odds['odds'],
                'min_bet' => $odds['min_bet'],
                'max_bet' => $odds['max_bet'],
                'bet_category' => $odds['bet_category']
            ];
        }

        return $processedBets;
    }

    /**
     * 计算投注总金额
     */
    private function calculateTotalAmount(array $processedBets): float
    {
        return array_sum(array_column($processedBets, 'bet_amount'));
    }

    /**
     * 验证投注限额
     */
    private function validateBetLimits(int $userId, int $tableId, array $processedBets, float $totalAmount): void
    {
        // 1. 检查单次投注总额限制
        $maxTotalBet = $this->getMaxTotalBetAmount($userId, $tableId);
        if ($totalAmount > $maxTotalBet) {
            throw new \Exception("投注总额超过限制: {$maxTotalBet}");
        }

        // 2. 检查单日投注限制
        $dailyBetAmount = $this->getUserDailyBetAmount($userId);
        $maxDailyBet = $this->getMaxDailyBetAmount($userId);
        if (($dailyBetAmount + $totalAmount) > $maxDailyBet) {
            throw new \Exception("超过单日投注限额");
        }

        // 3. 检查投注类型限制
        foreach ($processedBets as $bet) {
            $this->validateBetTypeLimit($userId, $bet);
        }
    }

    /**
     * 验证用户余额
     */
    private function validateUserBalance(UserModel $user, float $totalAmount): void
    {
        if ($user->money_balance < $totalAmount) {
            throw new \Exception('账户余额不足');
        }

        // 检查可用余额（排除冻结金额）
        $frozenAmount = $this->getUserFrozenAmount($user->id);
        $availableBalance = $user->money_balance - $frozenAmount;
        
        if ($availableBalance < $totalAmount) {
            throw new \Exception('可用余额不足');
        }
    }

    /**
     * 执行风控检查
     */
    private function performRiskControl(int $userId, int $tableId, array $processedBets, float $totalAmount): void
    {
        // 1. 频率风控
        $this->checkBettingFrequency($userId);
        
        // 2. 金额风控
        $this->checkBettingAmount($userId, $totalAmount);
        
        // 3. 模式风控
        $this->checkBettingPattern($userId, $processedBets);
        
        // 4. 台桌风控
        $this->checkTableRisk($tableId, $totalAmount);
    }

    /**
     * 处理之前的投注
     */
    private function handlePreviousBets(int $userId, string $gameNumber): float
    {
        $existingBets = SicboBetRecords::where('user_id', $userId)
            ->where('game_number', $gameNumber)
            ->where('settle_status', self::BET_STATUS_PENDING)
            ->select();

        if ($existingBets->isEmpty()) {
            return 0;
        }

        $refundAmount = 0;
        
        // 取消之前的投注
        foreach ($existingBets as $bet) {
            $bet->settle_status = self::BET_STATUS_CANCELLED;
            $bet->cancel_reason = '重新投注';
            $bet->settle_time = date('Y-m-d H:i:s');
            $bet->save();
            
            $refundAmount += $bet->bet_amount;
        }

        return $refundAmount;
    }

    /**
     * 执行投注事务
     */
    private function executeBetTransaction(int $userId, int $tableId, string $gameNumber, array $processedBets, float $totalAmount, float $previousRefund, array $gameSession): array
    {
        Db::startTrans();
        
        try {
            // 1. 锁定用户记录
            $user = UserModel::where('id', $userId)->lock(true)->find();
            $oldBalance = $user->money_balance;
            
            // 2. 计算新余额（加上之前的退款，减去新的投注）
            $newBalance = $oldBalance + $previousRefund - $totalAmount;
            
            if ($newBalance < 0) {
                throw new \Exception('余额不足');
            }

            // 3. 更新用户余额
            $user->money_balance = $newBalance;
            $user->save();

            // 4. 创建投注记录
            $betIds = [];
            $currentTime = date('Y-m-d H:i:s');
            
            foreach ($processedBets as $bet) {
                $betRecord = SicboBetRecords::create([
                    'user_id' => $userId,
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'round_number' => $gameSession['round_number'],
                    'bet_type' => $bet['bet_type'],
                    'bet_amount' => $bet['bet_amount'],
                    'odds' => $bet['odds'],
                    'balance_before' => $oldBalance + $previousRefund,
                    'balance_after' => $newBalance,
                    'settle_status' => self::BET_STATUS_PENDING,
                    'bet_time' => $currentTime
                ]);
                
                $betIds[] = $betRecord->id;
            }

            // 5. 记录资金日志
            if ($previousRefund > 0) {
                $this->createMoneyLog($userId, self::MONEY_TYPE_REFUND, $previousRefund, $oldBalance, $oldBalance + $previousRefund, '重新投注退款');
            }
            
            $this->createMoneyLog($userId, self::MONEY_TYPE_BET, $totalAmount, $oldBalance + $previousRefund, $newBalance, "骰宝投注: {$gameNumber}");

            Db::commit();

            return [
                'bet_ids' => $betIds,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'refund_amount' => $previousRefund
            ];

        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 投注后续处理
     */
    private function postBetProcessing(int $userId, int $tableId, string $gameNumber, array $betResult): void
    {
        try {
            // 1. 清除相关缓存
            $this->clearUserBetCache($userId, $gameNumber);
            $this->clearGameBetCache($gameNumber);
            
            // 2. 更新用户投注统计
            $this->updateUserBetStatistics($userId, $betResult);
            
            // 3. 记录投注行为日志
            $this->logBettingBehavior($userId, $tableId, $gameNumber, $betResult);

        } catch (\Exception $e) {
            LogHelper::error('投注后续处理失败', [
                'user_id' => $userId,
                'game_number' => $gameNumber,
                'error' => $e->getMessage()
            ]);
            // 后续处理失败不影响主流程
        }
    }

    /**
     * ========================================
     * 辅助和工具方法
     * ========================================
     */

    /**
     * 计算用户投注统计
     */
    private function calculateUserBetStatistics(int $userId, array $filters): array
    {
        $query = SicboBetRecords::where('user_id', $userId);
        
        // 应用筛选条件
        if (!empty($filters['start_date'])) {
            $query->where('bet_time', '>=', $filters['start_date'] . ' 00:00:00');
        }
        
        if (!empty($filters['end_date'])) {
            $query->where('bet_time', '<=', $filters['end_date'] . ' 23:59:59');
        }

        $stats = $query->field([
            'count(*) as total_bets',
            'sum(bet_amount) as total_bet_amount',
            'sum(win_amount) as total_win_amount',
            'count(case when is_win = 1 then 1 end) as win_count',
            'avg(bet_amount) as avg_bet_amount',
            'max(bet_amount) as max_bet_amount'
        ])->find();

        if (!$stats) {
            return [
                'total_bets' => 0,
                'total_bet_amount' => 0,
                'total_win_amount' => 0,
                'net_result' => 0,
                'win_count' => 0,
                'win_rate' => 0,
                'avg_bet_amount' => 0,
                'max_bet_amount' => 0
            ];
        }

        $statsArray = $stats->toArray();
        $netResult = $statsArray['total_win_amount'] - $statsArray['total_bet_amount'];
        $winRate = $statsArray['total_bets'] > 0 ? round($statsArray['win_count'] / $statsArray['total_bets'] * 100, 2) : 0;

        return array_merge($statsArray, [
            'net_result' => $netResult,
            'win_rate' => $winRate
        ]);
    }

    /**
     * 检查用户是否在黑名单
     */
    private function isUserBlacklisted(int $userId): bool
    {
        // 这里应该检查用户黑名单
        return Cache::get("sicbo:blacklist:{$userId}", false);
    }

    /**
     * 获取最大总投注金额
     */
    private function getMaxTotalBetAmount(int $userId, int $tableId): float
    {
        // 这里应该根据用户等级和台桌配置获取限额
        return 100000; // 默认10万
    }

    /**
     * 获取用户今日投注金额
     */
    private function getUserDailyBetAmount(int $userId): float
    {
        $today = date('Y-m-d');
        return SicboBetRecords::where('user_id', $userId)
            ->whereBetweenTime('bet_time', $today . ' 00:00:00', $today . ' 23:59:59')
            ->sum('bet_amount');
    }

    /**
     * 获取最大日投注金额
     */
    private function getMaxDailyBetAmount(int $userId): float
    {
        return 500000; // 默认50万
    }

    /**
     * 验证投注类型限制
     */
    private function validateBetTypeLimit(int $userId, array $bet): void
    {
        // 检查特定投注类型的限制
        $typeLimit = $this->getBetTypeLimit($userId, $bet['bet_type']);
        if ($bet['bet_amount'] > $typeLimit) {
            throw new \Exception("投注类型 {$bet['bet_type']} 超过限额");
        }
    }

    /**
     * 获取投注类型限额
     */
    private function getBetTypeLimit(int $userId, string $betType): float
    {
        // 这里应该根据投注类型返回相应限额
        return 50000; // 默认5万
    }

    /**
     * 获取用户冻结金额
     */
    private function getUserFrozenAmount(int $userId): float
    {
        return SicboBetRecords::where('user_id', $userId)
            ->where('settle_status', self::BET_STATUS_PENDING)
            ->sum('bet_amount');
    }

    /**
     * 检查投注频率
     */
    private function checkBettingFrequency(int $userId): void
    {
        $key = "sicbo:freq:{$userId}";
        $count = Cache::get($key, 0);
        
        if ($count >= 10) { // 每分钟最多10次
            throw new \Exception('投注过于频繁，请稍后再试');
        }
        
        Cache::set($key, $count + 1, 60);
    }

    /**
     * 检查投注金额
     */
    private function checkBettingAmount(int $userId, float $amount): void
    {
        // 检查异常大额投注
        if ($amount > 50000) {
            LogHelper::debug('检测到大额投注', [
                'user_id' => $userId,
                'amount' => $amount
            ]);
        }
    }

    /**
     * 检查投注模式
     */
    private function checkBettingPattern(int $userId, array $bets): void
    {
        // 检查异常投注模式
        // 比如：总是投注相同类型、金额规律等
    }

    /**
     * 检查台桌风险
     */
    private function checkTableRisk(int $tableId, float $amount): void
    {
        // 检查台桌的投注风险
        $tableRisk = Cache::get("sicbo:table_risk:{$tableId}", 0);
        if ($tableRisk > 1000000) { // 台桌风险超过100万
            throw new \Exception('台桌投注风险过高');
        }
    }

    /**
     * 创建资金日志
     */
    private function createMoneyLog(int $userId, int $type, float $amount, float $beforeBalance, float $afterBalance, string $mark): void
    {
        MoneyLog::create([
            'uid' => $userId,
            'type' => $amount > 0 ? 1 : 2, // 1=收入, 2=支出
            'status' => $type,
            'money' => abs($amount),
            'money_before' => $beforeBalance,
            'money_end' => $afterBalance,
            'source_id' => 0,
            'mark' => $mark,
            'create_time' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 清除用户投注缓存
     */
    private function clearUserBetCache(int $userId, string $gameNumber): void
    {
        $cacheKey = self::CACHE_PREFIX_USER_BET . "{$userId}:{$gameNumber}";
        Cache::delete($cacheKey);
    }

    /**
     * 清除游戏投注缓存
     */
    private function clearGameBetCache(string $gameNumber): void
    {
        $cacheKey = self::CACHE_PREFIX_GAME_BET . $gameNumber;
        Cache::delete($cacheKey);
    }

    /**
     * 更新用户投注统计
     */
    private function updateUserBetStatistics(int $userId, array $betResult): void
    {
        // 这里可以更新用户的投注统计信息
        // 比如：总投注次数、总投注金额等
    }

    /**
     * 记录投注行为日志
     */
    private function logBettingBehavior(int $userId, int $tableId, string $gameNumber, array $betResult): void
    {
        LogHelper::business('用户投注行为', [
            'user_id' => $userId,
            'table_id' => $tableId,
            'game_number' => $gameNumber,
            'bet_count' => count($betResult['bet_ids']),
            'total_amount' => $betResult['new_balance'] - $betResult['old_balance'] + $betResult['refund_amount'],
            'ip' => request()->ip() ?? '',
            'user_agent' => request()->header('user-agent') ?? ''
        ]);
    }
}