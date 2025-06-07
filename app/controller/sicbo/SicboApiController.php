<?php


namespace app\controller\sicbo;

use app\BaseController;
use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboOdds;
use app\model\sicbo\SicboStatistics;
use app\model\sicbo\SicboBetRecords;
use app\model\Table;
use app\model\UserModel;
use app\validate\sicbo\SicboApiValidate;
use think\Response;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Db;

/**
 * 骰宝API接口控制器
 * 处理对外API接口、第三方集成、移动端API
 */
class SicboApiController extends BaseController
{
    // API版本
    const API_VERSION = '1.0';
    
    // 认证缓存前缀
    const AUTH_CACHE_PREFIX = 'sicbo_api_auth:';
    
    // 速率限制缓存前缀
    const RATE_LIMIT_PREFIX = 'sicbo_api_rate:';

    /**
     * ========================================
     * 基础API接口
     * ========================================
     */

    /**
     * API身份验证
     * 路由: POST /api/sicbo/auth
     */
    public function authenticate()
    {
        $params = $this->request->only(['api_key', 'secret', 'timestamp', 'signature']);
        
        try {
            validate(SicboApiValidate::class)->scene('authenticate')->check($params);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        $apiKey = $params['api_key'];
        $secret = $params['secret'];
        $timestamp = (int)$params['timestamp'];
        $signature = $params['signature'] ?? '';

        // 检查时间戳有效性（5分钟内）
        if (abs(time() - $timestamp) > 300) {
            return $this->apiError(401, 'Timestamp expired');
        }

        // 验证API密钥和签名
        $validationResult = $this->validateApiCredentials($apiKey, $secret, $timestamp, $signature);
        if (!$validationResult['valid']) {
            return $this->apiError(401, $validationResult['message']);
        }

        // 生成访问令牌
        $accessToken = $this->generateAccessToken($apiKey);
        
        // 缓存令牌信息（2小时有效）
        $tokenInfo = [
            'api_key' => $apiKey,
            'created_at' => time(),
            'expires_at' => time() + 7200,
            'permissions' => $validationResult['permissions']
        ];
        
        Cache::set(self::AUTH_CACHE_PREFIX . $accessToken, $tokenInfo, 7200);

        return $this->apiSuccess([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 7200,
            'permissions' => $validationResult['permissions'],
            'api_version' => self::API_VERSION
        ]);
    }

    /**
     * 获取台桌列表API
     * 路由: GET /api/sicbo/tables
     */
    public function apiGetTables()
    {
        // API认证中间件应该已经验证了token
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        // 检查权限
        if (!$this->hasPermission($authInfo, 'read_tables')) {
            return $this->apiError(403, 'Insufficient permissions');
        }

        // 速率限制检查
        if (!$this->checkRateLimit($authInfo['api_key'], 'get_tables', 100, 3600)) {
            return $this->apiError(429, 'Rate limit exceeded');
        }

        $gameType = $this->request->param('game_type', 'sicbo');
        $status = $this->request->param('status', 'active');

        // 获取骰宝台桌列表
        $query = Table::where('game_type', 9); // 9=骰宝

        if ($status === 'active') {
            $query->where('status', 1);
        }

        $tables = $query->order('id asc')->select()->toArray();

        $formattedTables = [];
        foreach ($tables as $table) {
            // 获取当前游戏状态
            $currentGame = cache("sicbo:current_game:{$table['id']}");
            
            $formattedTables[] = [
                'table_id' => $table['id'],
                'table_name' => $table['table_title'],
                'status' => $table['status'],
                'run_status' => $table['run_status'],
                'current_game' => $currentGame ? [
                    'game_number' => $currentGame['game_number'],
                    'status' => $currentGame['status'],
                    'countdown' => max(0, $currentGame['betting_end_time'] - time())
                ] : null,
                'betting_limits' => $this->getTableBettingLimits($table['id']),
            ];
        }

        return $this->apiSuccess([
            'tables' => $formattedTables,
            'total_count' => count($formattedTables),
            'game_type' => 'sicbo'
        ]);
    }

    /**
     * 获取游戏状态API
     * 路由: GET /api/sicbo/game-status/{table_id}
     */
    public function apiGetGameStatus()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $tableId = $this->request->param('table_id/d', 0);
        
        try {
            validate(SicboApiValidate::class)->scene('game_status')->check(['table_id' => $tableId]);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        // 速率限制检查
        if (!$this->checkRateLimit($authInfo['api_key'], 'get_game_status', 200, 3600)) {
            return $this->apiError(429, 'Rate limit exceeded');
        }

        // 获取台桌信息
        $table = Table::find($tableId);
        if (!$table || $table->game_type != 9) {
            return $this->apiError(404, 'Table not found');
        }

        // 获取当前游戏状态
        $currentGame = cache("sicbo:current_game:{$tableId}");
        
        // 获取最新开奖结果
        $latestResult = SicboGameResults::getLastGame($tableId);
        
        // 获取今日统计
        $todayStats = SicboStatistics::calculateTodayStats($tableId);

        $gameStatus = [
            'table_id' => $tableId,
            'table_name' => $table->table_title,
            'table_status' => $table->status,
            'run_status' => $table->run_status,
            'current_game' => $currentGame,
            'latest_result' => $latestResult,
            'today_stats' => $todayStats,
            'countdown' => $currentGame ? max(0, $currentGame['betting_end_time'] - time()) : 0,
            'timestamp' => time()
        ];

        return $this->apiSuccess($gameStatus);
    }

    /**
     * ========================================
     * 投注相关API
     * ========================================
     */

    /**
     * API投注接口
     * 路由: POST /api/sicbo/bet
     */
    public function apiBet()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        // 检查投注权限
        if (!$this->hasPermission($authInfo, 'place_bet')) {
            return $this->apiError(403, 'Insufficient permissions for betting');
        }

        $params = $this->request->only(['table_id', 'user_token', 'bets', 'total_amount']);
        
        try {
            validate(SicboApiValidate::class)->scene('api_bet')->check($params);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        $tableId = (int)$params['table_id'];
        $userToken = $params['user_token'];
        $bets = $params['bets'];
        $totalAmount = (float)$params['total_amount'];

        // 速率限制检查（投注限制更严格）
        if (!$this->checkRateLimit($authInfo['api_key'], 'place_bet', 50, 3600)) {
            return $this->apiError(429, 'Betting rate limit exceeded');
        }

        // 验证用户令牌
        $userId = $this->validateUserToken($userToken);
        if (!$userId) {
            return $this->apiError(401, 'Invalid user token');
        }

        // 检查台桌状态
        $table = Table::find($tableId);
        if (!$table || $table->game_type != 9 || $table->status != 1) {
            return $this->apiError(400, 'Table unavailable');
        }

        // 检查游戏状态
        $currentGame = cache("sicbo:current_game:{$tableId}");
        if (!$currentGame || $currentGame['status'] != 'betting') {
            return $this->apiError(400, 'Not in betting time');
        }

        // 检查投注时间
        if (time() > $currentGame['betting_end_time']) {
            return $this->apiError(400, 'Betting time expired');
        }

        // 获取用户信息
        $user = UserModel::find($userId);
        if (!$user) {
            return $this->apiError(404, 'User not found');
        }

        // 验证投注数据
        $validationResult = $this->validateApiBets($bets, $totalAmount);
        if (!$validationResult['valid']) {
            return $this->apiError(400, $validationResult['message']);
        }

        // 检查用户余额
        if ($user->money_balance < $totalAmount) {
            return $this->apiError(400, 'Insufficient balance', [
                'current_balance' => $user->money_balance,
                'required_amount' => $totalAmount
            ]);
        }

        try {
            Db::startTrans();

            // 取消现有投注（如果有）
            $existingBets = SicboBetRecords::getCurrentBets($userId, $currentGame['game_number']);
            $refundAmount = 0;
            
            if (!empty($existingBets)) {
                $refundAmount = array_sum(array_column($existingBets, 'bet_amount'));
                SicboBetRecords::cancelCurrentBets($userId, $currentGame['game_number']);
                $user->money_balance += $refundAmount;
            }

            // 扣除投注金额
            $user->money_balance -= $totalAmount;
            $user->save();

            // 创建投注记录
            $betRecords = [];
            foreach ($bets as $bet) {
                $oddsInfo = SicboOdds::getOddsByBetType($bet['bet_type']);
                
                $betRecords[] = [
                    'user_id' => $userId,
                    'table_id' => $tableId,
                    'game_number' => $currentGame['game_number'],
                    'round_number' => $currentGame['round_number'],
                    'bet_type' => $bet['bet_type'],
                    'bet_amount' => $bet['bet_amount'],
                    'odds' => $oddsInfo['odds'],
                    'balance_before' => $user->money_balance + $bet['bet_amount'],
                    'balance_after' => $user->money_balance,
                    'bet_time' => date('Y-m-d H:i:s'),
                ];
            }

            if (!SicboBetRecords::createBatchBets($betRecords)) {
                throw new \Exception('Failed to create bet records');
            }

            Db::commit();

            return $this->apiSuccess([
                'game_number' => $currentGame['game_number'],
                'total_amount' => $totalAmount,
                'bet_count' => count($bets),
                'current_balance' => $user->money_balance,
                'refund_amount' => $refundAmount,
                'bet_id' => $currentGame['game_number'] . '_' . $userId,
                'timestamp' => time()
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return $this->apiError(500, 'Bet placement failed: ' . $e->getMessage());
        }
    }

    /**
     * 查询投注结果API
     * 路由: GET /api/sicbo/bet-result/{game_number}
     */
    public function apiGetBetResult()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $gameNumber = $this->request->param('game_number', '');
        $userToken = $this->request->param('user_token', '');
        
        try {
            validate(SicboApiValidate::class)->scene('bet_result')->check([
                'game_number' => $gameNumber,
                'user_token' => $userToken
            ]);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        // 验证用户令牌
        $userId = $this->validateUserToken($userToken);
        if (!$userId) {
            return $this->apiError(401, 'Invalid user token');
        }

        // 获取游戏结果
        $gameResult = SicboGameResults::getByGameNumber($gameNumber);
        if (!$gameResult) {
            return $this->apiError(404, 'Game result not found');
        }

        // 获取用户投注记录
        $userBets = SicboBetRecords::where('user_id', $userId)
            ->where('game_number', $gameNumber)
            ->select()
            ->toArray();

        if (empty($userBets)) {
            return $this->apiError(404, 'No bets found for this game');
        }

        // 计算总输赢
        $totalBetAmount = array_sum(array_column($userBets, 'bet_amount'));
        $totalWinAmount = array_sum(array_column($userBets, 'win_amount'));
        $netResult = $totalWinAmount - $totalBetAmount;

        $result = [
            'game_number' => $gameNumber,
            'game_result' => [
                'dice1' => $gameResult['dice1'],
                'dice2' => $gameResult['dice2'],
                'dice3' => $gameResult['dice3'],
                'total_points' => $gameResult['total_points'],
                'is_big' => $gameResult['is_big'],
                'is_odd' => $gameResult['is_odd'],
                'winning_bets' => $gameResult['winning_bets']
            ],
            'user_bets' => array_map(function($bet) {
                return [
                    'bet_type' => $bet['bet_type'],
                    'bet_amount' => $bet['bet_amount'],
                    'odds' => $bet['odds'],
                    'is_win' => $bet['is_win'],
                    'win_amount' => $bet['win_amount']
                ];
            }, $userBets),
            'summary' => [
                'total_bet_amount' => $totalBetAmount,
                'total_win_amount' => $totalWinAmount,
                'net_result' => $netResult,
                'result_type' => $netResult > 0 ? 'win' : ($netResult < 0 ? 'loss' : 'break_even')
            ],
            'timestamp' => time()
        ];

        return $this->apiSuccess($result);
    }

    /**
     * 获取用户余额API
     * 路由: GET /api/sicbo/balance
     */
    public function apiGetBalance()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $userToken = $this->request->param('user_token', '');
        
        try {
            validate(SicboApiValidate::class)->scene('get_balance')->check(['user_token' => $userToken]);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        // 验证用户令牌
        $userId = $this->validateUserToken($userToken);
        if (!$userId) {
            return $this->apiError(401, 'Invalid user token');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->apiError(404, 'User not found');
        }

        // 计算冻结金额
        $frozenAmount = SicboBetRecords::where('user_id', $userId)
            ->where('settle_status', SicboBetRecords::SETTLE_STATUS_PENDING)
            ->sum('bet_amount');

        return $this->apiSuccess([
            'user_id' => $userId,
            'total_balance' => $user->money_balance,
            'frozen_amount' => (float)$frozenAmount,
            'available_balance' => $user->money_balance - $frozenAmount,
            'currency' => 'USD', // 或者从配置获取
            'timestamp' => time()
        ]);
    }

    /**
     * ========================================
     * 数据查询API
     * ========================================
     */

    /**
     * 获取开奖历史API
     * 路由: GET /api/sicbo/results
     */
    public function apiGetResults()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $tableId = $this->request->param('table_id/d', 0);
        $limit = $this->request->param('limit/d', 20);
        $dateRange = $this->request->param('date_range', '');
        
        try {
            validate(SicboApiValidate::class)->scene('get_results')->check([
                'table_id' => $tableId,
                'limit' => $limit
            ]);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        // 速率限制
        if (!$this->checkRateLimit($authInfo['api_key'], 'get_results', 100, 3600)) {
            return $this->apiError(429, 'Rate limit exceeded');
        }

        $query = SicboGameResults::where('table_id', $tableId)->where('status', 1);

        // 处理日期范围
        if (!empty($dateRange)) {
            $dates = explode(',', $dateRange);
            if (count($dates) == 2) {
                $query->whereBetweenTime('created_at', $dates[0] . ' 00:00:00', $dates[1] . ' 23:59:59');
            }
        }

        $results = $query->order('id desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return $this->apiSuccess([
            'table_id' => $tableId,
            'results' => array_map(function($result) {
                return [
                    'game_number' => $result['game_number'],
                    'dice1' => $result['dice1'],
                    'dice2' => $result['dice2'],
                    'dice3' => $result['dice3'],
                    'total_points' => $result['total_points'],
                    'is_big' => $result['is_big'],
                    'is_odd' => $result['is_odd'],
                    'has_triple' => $result['has_triple'],
                    'triple_number' => $result['triple_number'],
                    'has_pair' => $result['has_pair'],
                    'winning_bets' => $result['winning_bets'],
                    'created_at' => $result['created_at']
                ];
            }, $results),
            'count' => count($results),
            'timestamp' => time()
        ]);
    }

    /**
     * 获取赔率信息API
     * 路由: GET /api/sicbo/odds/{table_id}
     */
    public function apiGetOdds()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $tableId = $this->request->param('table_id/d', 0);
        
        try {
            validate(SicboApiValidate::class)->scene('get_odds')->check(['table_id' => $tableId]);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        // 获取所有有效赔率
        $odds = SicboOdds::getAllActiveOdds();
        
        // 按分类组织
        $organizedOdds = [];
        foreach ($odds as $odd) {
            $category = $odd['bet_category'];
            if (!isset($organizedOdds[$category])) {
                $organizedOdds[$category] = [];
            }
            $organizedOdds[$category][] = [
                'bet_type' => $odd['bet_type'],
                'bet_name' => $odd['bet_name_cn'],
                'bet_name_en' => $odd['bet_name_en'],
                'odds' => $odd['odds'],
                'min_bet' => $odd['min_bet'],
                'max_bet' => $odd['max_bet']
            ];
        }

        return $this->apiSuccess([
            'table_id' => $tableId,
            'odds' => $organizedOdds,
            'api_version' => self::API_VERSION,
            'timestamp' => time()
        ]);
    }

    /**
     * 获取统计数据API
     * 路由: GET /api/sicbo/statistics/{table_id}
     */
    public function apiGetStatistics()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $tableId = $this->request->param('table_id/d', 0);
        $period = $this->request->param('period', '24h');
        
        try {
            validate(SicboApiValidate::class)->scene('get_statistics')->check([
                'table_id' => $tableId,
                'period' => $period
            ]);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        $statistics = [];

        switch ($period) {
            case '1h':
                $statistics = SicboStatistics::getHourlyStats($tableId, 1);
                break;
            case '24h':
                $statistics = SicboStatistics::calculateTodayStats($tableId);
                break;
            case '7d':
                $statistics = SicboStatistics::getWinRateStats($tableId, 'week');
                break;
            default:
                $statistics = SicboStatistics::calculateTodayStats($tableId);
        }

        return $this->apiSuccess([
            'table_id' => $tableId,
            'period' => $period,
            'statistics' => $statistics,
            'timestamp' => time()
        ]);
    }

    /**
     * ========================================
     * 移动端专用接口
     * ========================================
     */

    /**
     * 移动端快速投注
     * 路由: POST /api/sicbo/mobile/quick-bet
     */
    public function mobileQuickBet()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $params = $this->request->only(['table_id', 'user_token', 'bet_type', 'amount']);
        
        try {
            validate(SicboApiValidate::class)->scene('mobile_quick_bet')->check($params);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        $tableId = (int)$params['table_id'];
        $userToken = $params['user_token'];
        $betType = $params['bet_type'];
        $amount = (float)$params['amount'];

        // 转换为标准投注格式
        $bets = [
            [
                'bet_type' => $betType,
                'bet_amount' => $amount
            ]
        ];

        // 调用标准投注方法
        $params['bets'] = $bets;
        $params['total_amount'] = $amount;

        return $this->apiBet();
    }

    /**
     * 移动端游戏状态推送注册
     * 路由: POST /api/sicbo/mobile/subscribe
     */
    public function mobileSubscribe()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $params = $this->request->only(['device_token', 'table_id', 'user_token', 'platform']);
        
        try {
            validate(SicboApiValidate::class)->scene('mobile_subscribe')->check($params);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        $deviceToken = $params['device_token'];
        $tableId = (int)$params['table_id'];
        $userToken = $params['user_token'];
        $platform = $params['platform']; // ios/android

        // 验证用户令牌
        $userId = $this->validateUserToken($userToken);
        if (!$userId) {
            return $this->apiError(401, 'Invalid user token');
        }

        // 存储推送订阅信息
        $subscriptionKey = "sicbo:push_subscription:{$tableId}:{$userId}";
        $subscriptionData = [
            'device_token' => $deviceToken,
            'platform' => $platform,
            'user_id' => $userId,
            'table_id' => $tableId,
            'created_at' => time()
        ];

        Cache::set($subscriptionKey, $subscriptionData, 3600 * 24); // 24小时有效

        return $this->apiSuccess([
            'subscription_id' => md5($subscriptionKey),
            'status' => 'subscribed',
            'table_id' => $tableId,
            'platform' => $platform
        ]);
    }

    /**
     * 移动端用户偏好设置
     * 路由: PUT /api/sicbo/mobile/preferences
     */
    public function mobileSetPreferences()
    {
        $authInfo = $this->getAuthInfo();
        if (!$authInfo) {
            return $this->apiError(401, 'Unauthorized');
        }

        $params = $this->request->only(['user_token', 'preferences']);
        
        try {
            validate(SicboApiValidate::class)->scene('mobile_preferences')->check($params);
        } catch (ValidateException $e) {
            return $this->apiError(400, $e->getError());
        }

        $userToken = $params['user_token'];
        $preferences = $params['preferences'];

        // 验证用户令牌
        $userId = $this->validateUserToken($userToken);
        if (!$userId) {
            return $this->apiError(401, 'Invalid user token');
        }

        // 保存用户偏好设置
        $preferencesKey = "sicbo:user_preferences:{$userId}";
        $defaultPreferences = [
            'sound_enabled' => true,
            'vibration_enabled' => true,
            'auto_bet_enabled' => false,
            'quick_bet_amounts' => [10, 20, 50, 100],
            'preferred_bet_types' => ['big', 'small', 'odd', 'even'],
            'notification_types' => ['game_start', 'game_result', 'bet_reminder']
        ];

        $updatedPreferences = array_merge($defaultPreferences, $preferences);
        Cache::set($preferencesKey, $updatedPreferences, 3600 * 24 * 30); // 30天有效

        return $this->apiSuccess([
            'preferences' => $updatedPreferences,
            'updated_at' => time()
        ]);
    }

    /**
     * ========================================
     * 私有辅助方法
     * ========================================
     */

    /**
     * 验证API凭据
     */
    private function validateApiCredentials(string $apiKey, string $secret, int $timestamp, string $signature): array
    {
        // 这里应该从数据库查询API密钥信息
        // 暂时使用硬编码的测试密钥
        $validApiKeys = [
            'test_api_key_001' => [
                'secret' => 'test_secret_001',
                'permissions' => ['read_tables', 'place_bet', 'read_results'],
                'active' => true
            ],
            'readonly_api_key' => [
                'secret' => 'readonly_secret',
                'permissions' => ['read_tables', 'read_results'],
                'active' => true
            ]
        ];

        if (!isset($validApiKeys[$apiKey])) {
            return ['valid' => false, 'message' => 'Invalid API key'];
        }

        $keyInfo = $validApiKeys[$apiKey];
        if (!$keyInfo['active']) {
            return ['valid' => false, 'message' => 'API key is inactive'];
        }

        // 验证签名（简单的HMAC-SHA256签名）
        $expectedSignature = hash_hmac('sha256', $apiKey . $timestamp, $keyInfo['secret']);
        if (!hash_equals($expectedSignature, $signature)) {
            return ['valid' => false, 'message' => 'Invalid signature'];
        }

        return [
            'valid' => true,
            'permissions' => $keyInfo['permissions'],
            'api_key' => $apiKey
        ];
    }

    /**
     * 生成访问令牌
     */
    private function generateAccessToken(string $apiKey): string
    {
        return 'sicbo_' . md5($apiKey . time() . rand(1000, 9999));
    }

    /**
     * 获取认证信息
     */
    private function getAuthInfo(): ?array
    {
        $authorization = $this->request->header('Authorization', '');
        if (!preg_match('/Bearer\s+(.+)/', $authorization, $matches)) {
            return null;
        }

        $token = $matches[1];
        return Cache::get(self::AUTH_CACHE_PREFIX . $token);
    }

    /**
     * 检查权限
     */
    private function hasPermission(array $authInfo, string $permission): bool
    {
        return in_array($permission, $authInfo['permissions'] ?? []);
    }

    /**
     * 速率限制检查
     */
    private function checkRateLimit(string $apiKey, string $action, int $limit, int $window): bool
    {
        $key = self::RATE_LIMIT_PREFIX . $apiKey . ':' . $action . ':' . floor(time() / $window);
        $current = Cache::get($key, 0);
        
        if ($current >= $limit) {
            return false;
        }

        Cache::set($key, $current + 1, $window);
        return true;
    }

    /**
     * 验证用户令牌
     */
    private function validateUserToken(string $userToken): ?int
    {
        // 这里应该验证用户令牌的有效性
        // 暂时使用简单的解码方式
        if (preg_match('/^user_(\d+)_(.+)$/', $userToken, $matches)) {
            $userId = (int)$matches[1];
            $hash = $matches[2];
            
            // 验证hash的有效性（这里简化处理）
            $expectedHash = md5($userId . 'user_token_secret');
            if ($hash === $expectedHash) {
                return $userId;
            }
        }
        
        return null;
    }

    /**
     * 验证API投注数据
     */
    private function validateApiBets(array $bets, float $totalAmount): array
    {
        if (empty($bets)) {
            return ['valid' => false, 'message' => 'Bets cannot be empty'];
        }

        $calculatedTotal = 0;
        foreach ($bets as $bet) {
            if (!isset($bet['bet_type']) || !isset($bet['bet_amount'])) {
                return ['valid' => false, 'message' => 'Invalid bet format'];
            }

            $betAmount = (float)$bet['bet_amount'];
            $calculatedTotal += $betAmount;

            // 检查投注类型
            $odds = SicboOdds::getOddsByBetType($bet['bet_type']);
            if (!$odds) {
                return ['valid' => false, 'message' => "Invalid bet type: {$bet['bet_type']}"];
            }

            // 检查投注金额
            if (!SicboOdds::validateBetAmount($bet['bet_type'], $betAmount)) {
                return ['valid' => false, 'message' => "Bet amount out of range for {$bet['bet_type']}"];
            }
        }

        // 检查总金额
        if (abs($calculatedTotal - $totalAmount) > 0.01) {
            return ['valid' => false, 'message' => 'Total amount mismatch'];
        }

        return ['valid' => true];
    }

    /**
     * 获取台桌投注限额
     */
    private function getTableBettingLimits(int $tableId): array
    {
        // 获取基础限额设置
        $basicLimits = [
            'min_bet' => 10,
            'max_bet' => 50000,
            'max_total_bet' => 100000
        ];

        // 可以根据台桌配置调整限额
        $table = Table::find($tableId);
        if ($table && $table->game_config) {
            $config = is_string($table->game_config) ? json_decode($table->game_config, true) : $table->game_config;
            $limits = $config['limits'] ?? [];
            $basicLimits = array_merge($basicLimits, $limits);
        }

        return $basicLimits;
    }

    /**
     * API成功响应
     */
    private function apiSuccess(array $data = [], string $message = 'success')
    {
        return json([
            'success' => true,
            'code' => 200,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
            'api_version' => self::API_VERSION
        ]);
    }

    /**
     * API错误响应
     */
    private function apiError(int $code, string $message, array $data = [])
    {
        $response = [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'timestamp' => time(),
            'api_version' => self::API_VERSION
        ];

        if (!empty($data)) {
            $response['data'] = $data;
        }

        return json($response, $code >= 500 ? 500 : 200);
    }
}