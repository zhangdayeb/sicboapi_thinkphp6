<?php


namespace app\controller\sicbo;

use app\BaseController;
use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboOdds;
use app\model\sicbo\SicboStatistics;
use app\model\sicbo\SicboBetRecords;
use app\model\Table;
use app\model\UserModel;
use app\validate\sicbo\SicboAdminValidate;
use think\Response;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 骰宝管理后台控制器
 * 处理后台管理功能、荷官操作、系统配置
 */
class SicboAdminController extends BaseController
{
    /**
     * ========================================
     * 台桌管理相关方法
     * ========================================
     */

    /**
     * 获取台桌列表
     * 路由: GET /sicbo/admin/tables
     */
    public function getTableList()
    {
        // 获取所有骰宝台桌
        $tables = Table::where('game_type', 9)
            ->order('id asc')
            ->select()
            ->toArray();

        $formattedTables = [];
        foreach ($tables as $table) {
            // 获取台桌实时状态
            $currentGame = cache("sicbo:current_game:{$table['id']}");
            
            // 获取今日统计
            $todayStats = SicboStatistics::calculateTodayStats($table['id']);
            
            // 获取在线人数
            $onlineCount = $this->getTableOnlineCount($table['id']);
            
            $formattedTables[] = [
                'table_id' => $table['id'],
                'table_name' => $table['table_title'],
                'status' => $table['status'],
                'run_status' => $table['run_status'],
                'game_config' => $table['game_config'],
                'current_game' => $currentGame,
                'today_stats' => $todayStats,
                'online_count' => $onlineCount,
                'update_time' => $table['update_time'],
            ];
        }

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'tables' => $formattedTables,
                'total_count' => count($formattedTables)
            ]
        ]);
    }

    /**
     * 更新台桌设置
     * 路由: PUT /sicbo/admin/table/{table_id}
     */
    public function updateTableConfig()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $params = $this->request->only(['table_title', 'table_status', 'game_config']);
        $params['table_id'] = $tableId;
        
        try {
            validate(SicboAdminValidate::class)->scene('update_table')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $table = Table::find($tableId);
        if (!$table || $table->game_type != 9) {
            return json(['code' => 404, 'message' => '台桌不存在']);
        }

        // 准备更新数据
        $updateData = [];
        if (isset($params['table_title'])) {
            $updateData['table_title'] = $params['table_title'];
        }
        if (isset($params['table_status'])) {
            $updateData['status'] = $params['table_status'];
        }
        if (isset($params['game_config'])) {
            $updateData['game_config'] = $params['game_config'];
        }
        $updateData['update_time'] = time();

        $result = $table->save($updateData);
        
        if ($result) {
            return json([
                'code' => 200,
                'message' => '台桌配置更新成功',
                'data' => [
                    'table_id' => $tableId,
                    'updated_fields' => array_keys($updateData)
                ]
            ]);
        } else {
            return json(['code' => 500, 'message' => '台桌配置更新失败']);
        }
    }

    /**
     * 台桌开关控制
     * 路由: POST /sicbo/admin/table/{table_id}/toggle
     */
    public function toggleTableStatus()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $action = $this->request->param('action', '');
        $reason = $this->request->param('reason', '');
        
        try {
            validate(SicboAdminValidate::class)->scene('toggle_table')->check([
                'table_id' => $tableId,
                'action' => $action,
                'reason' => $reason
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $table = Table::find($tableId);
        if (!$table || $table->game_type != 9) {
            return json(['code' => 404, 'message' => '台桌不存在']);
        }

        try {
            switch ($action) {
                case 'open':
                    $table->save([
                        'status' => 1,
                        'run_status' => 0,
                        'update_time' => time()
                    ]);
                    $message = '台桌已开启';
                    break;
                    
                case 'close':
                    // 如果有正在进行的游戏，先强制结束
                    $this->forceEndCurrentGame($tableId, $reason);
                    
                    $table->save([
                        'status' => 0,
                        'run_status' => 0,
                        'update_time' => time()
                    ]);
                    $message = '台桌已关闭';
                    break;
                    
                case 'maintain':
                    $this->forceEndCurrentGame($tableId, $reason);
                    
                    $table->save([
                        'status' => 2, // 维护状态
                        'run_status' => 0,
                        'update_time' => time()
                    ]);
                    $message = '台桌进入维护模式';
                    break;
                    
                default:
                    return json(['code' => 400, 'message' => '无效的操作类型']);
            }

            return json([
                'code' => 200,
                'message' => $message,
                'data' => [
                    'table_id' => $tableId,
                    'action' => $action,
                    'new_status' => $table->status,
                    'reason' => $reason
                ]
            ]);

        } catch (\Exception $e) {
            return json(['code' => 500, 'message' => '操作失败：' . $e->getMessage()]);
        }
    }

    /**
     * ========================================
     * 荷官操作相关方法
     * ========================================
     */

    /**
     * 荷官开始游戏
     * 路由: POST /sicbo/admin/dealer/start-game
     */
    public function dealerStartGame()
    {
        $params = $this->request->only(['table_id', 'dealer_id', 'betting_duration']);
        
        try {
            validate(SicboAdminValidate::class)->scene('dealer_start')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $tableId = (int)$params['table_id'];
        $dealerId = (int)$params['dealer_id'];
        $bettingDuration = (int)($params['betting_duration'] ?? 30);

        // 检查台桌状态
        $table = Table::find($tableId);
        if (!$table || $table->game_type != 9 || $table->status != 1) {
            return json(['code' => 400, 'message' => '台桌不可用']);
        }

        if ($table->run_status == 1) {
            return json(['code' => 400, 'message' => '游戏已在进行中']);
        }

        // 生成新游戏局号
        $gameNumber = SicboGameResults::generateGameNumber($tableId);
        
        // 更新台桌状态
        $table->save([
            'run_status' => 1, // 投注中
            'start_time' => time(),
            'countdown_time' => $bettingDuration,
            'update_time' => time(),
        ]);

        // 缓存游戏信息
        $gameInfo = [
            'game_number' => $gameNumber,
            'table_id' => $tableId,
            'round_number' => SicboGameResults::getCurrentRoundNumber($tableId),
            'dealer_id' => $dealerId,
            'betting_start_time' => time(),
            'betting_end_time' => time() + $bettingDuration,
            'status' => 'betting'
        ];
        
        cache("sicbo:current_game:{$tableId}", $gameInfo, $bettingDuration + 60);

        // 记录荷官操作日志
        $this->logDealerAction($dealerId, $tableId, 'start_game', [
            'game_number' => $gameNumber,
            'betting_duration' => $bettingDuration
        ]);

        return json([
            'code' => 200,
            'message' => '游戏开始成功',
            'data' => [
                'game_number' => $gameNumber,
                'betting_duration' => $bettingDuration,
                'dealer_id' => $dealerId,
                'status' => 'betting'
            ]
        ]);
    }

    /**
     * 荷官录入开奖结果
     * 路由: POST /sicbo/admin/dealer/input-result
     */
    public function dealerInputResult()
    {
        $params = $this->request->only(['table_id', 'game_number', 'dice1', 'dice2', 'dice3', 'dealer_id']);
        
        try {
            validate(SicboAdminValidate::class)->scene('dealer_input')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $tableId = (int)$params['table_id'];
        $gameNumber = $params['game_number'];
        $dice1 = (int)$params['dice1'];
        $dice2 = (int)$params['dice2'];
        $dice3 = (int)$params['dice3'];
        $dealerId = (int)$params['dealer_id'];

        // 检查游戏状态
        $currentGame = cache("sicbo:current_game:{$tableId}");
        if (!$currentGame || $currentGame['game_number'] != $gameNumber) {
            return json(['code' => 400, 'message' => '游戏状态错误']);
        }

        // 检查是否已有结果
        if (SicboGameResults::gameExists($gameNumber)) {
            return json(['code' => 400, 'message' => '该局游戏结果已存在']);
        }

        try {
            Db::startTrans();

            // 创建游戏结果记录
            $gameResult = SicboGameResults::createGameResult([
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'round_number' => $currentGame['round_number'],
                'dice1' => $dice1,
                'dice2' => $dice2,
                'dice3' => $dice3,
            ]);

            // 计算中奖投注类型
            $winningBets = $this->calculateWinningBets($dice1, $dice2, $dice3);
            $gameResult->save(['winning_bets' => $winningBets]);

            // 触发投注结算
            SicboBetRecords::settleBets($gameNumber, $winningBets);

            // 更新台桌状态
            Table::where('id', $tableId)->update([
                'run_status' => 0, // 等待中
                'update_time' => time(),
            ]);

            // 清除游戏缓存
            cache("sicbo:current_game:{$tableId}", null);

            // 记录荷官操作日志
            $this->logDealerAction($dealerId, $tableId, 'input_result', [
                'game_number' => $gameNumber,
                'dice1' => $dice1,
                'dice2' => $dice2,
                'dice3' => $dice3,
                'winning_bets' => $winningBets
            ]);

            Db::commit();

            return json([
                'code' => 200,
                'message' => '开奖结果录入成功',
                'data' => [
                    'game_number' => $gameNumber,
                    'dice1' => $dice1,
                    'dice2' => $dice2,
                    'dice3' => $dice3,
                    'total_points' => $dice1 + $dice2 + $dice3,
                    'winning_bets' => $winningBets,
                    'dealer_id' => $dealerId
                ]
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'message' => '录入失败：' . $e->getMessage()]);
        }
    }

    /**
     * 荷官强制结束游戏
     * 路由: POST /sicbo/admin/dealer/force-end
     */
    public function dealerForceEnd()
    {
        $params = $this->request->only(['table_id', 'game_number', 'reason', 'dealer_id']);
        
        try {
            validate(SicboAdminValidate::class)->scene('dealer_force_end')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $tableId = (int)$params['table_id'];
        $gameNumber = $params['game_number'];
        $reason = $params['reason'];
        $dealerId = (int)$params['dealer_id'];

        try {
            $result = $this->forceEndCurrentGame($tableId, $reason, $gameNumber);
            
            if ($result) {
                // 记录荷官操作日志
                $this->logDealerAction($dealerId, $tableId, 'force_end', [
                    'game_number' => $gameNumber,
                    'reason' => $reason
                ]);

                return json([
                    'code' => 200,
                    'message' => '游戏已强制结束',
                    'data' => [
                        'table_id' => $tableId,
                        'game_number' => $gameNumber,
                        'reason' => $reason,
                        'dealer_id' => $dealerId
                    ]
                ]);
            } else {
                return json(['code' => 500, 'message' => '强制结束失败']);
            }

        } catch (\Exception $e) {
            return json(['code' => 500, 'message' => '操作失败：' . $e->getMessage()]);
        }
    }

    /**
     * ========================================
     * 数据监控和报表相关方法
     * ========================================
     */

    /**
     * 获取实时监控数据
     * 路由: GET /sicbo/admin/monitor/realtime
     */
    public function getRealtimeMonitor()
    {
        $tableId = $this->request->param('table_id/d', 0);
        
        try {
            validate(SicboAdminValidate::class)->scene('realtime_monitor')->check([
                'table_id' => $tableId,
                'monitor_type' => 'realtime'
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $monitorData = [];

        if ($tableId > 0) {
            // 单个台桌监控
            $monitorData = $this->getSingleTableMonitor($tableId);
        } else {
            // 全局监控
            $monitorData = $this->getGlobalMonitor();
        }

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => $monitorData
        ]);
    }

    /**
     * 获取财务报表
     * 路由: GET /sicbo/admin/report/financial
     */
    public function getFinancialReport()
    {
        $params = $this->request->only(['date_range', 'table_id', 'report_type', 'start_date', 'end_date']);
        
        try {
            validate(SicboAdminValidate::class)->scene('financial_report')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $startDate = $params['start_date'];
        $endDate = $params['end_date'];
        $tableId = (int)($params['table_id'] ?? 0);

        // 获取营收统计
        if ($tableId > 0) {
            $revenueStats = SicboStatistics::getRevenueStats($tableId, $startDate, $endDate);
        } else {
            $revenueStats = $this->getGlobalRevenueStats($startDate, $endDate);
        }

        // 获取详细投注数据
        $betDetails = $this->getBetDetails($tableId, $startDate, $endDate);

        // 获取用户统计
        $userStats = $this->getUserStats($tableId, $startDate, $endDate);

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'revenue_stats' => $revenueStats,
                'bet_details' => $betDetails,
                'user_stats' => $userStats,
                'query_params' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'table_id' => $tableId
                ]
            ]
        ]);
    }

    /**
     * 获取用户行为分析
     * 路由: GET /sicbo/admin/report/user-behavior
     */
    public function getUserBehaviorReport()
    {
        $params = $this->request->only(['date_range', 'user_type', 'start_date', 'end_date']);
        
        try {
            validate(SicboAdminValidate::class)->scene('user_behavior_report')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $startDate = $params['start_date'];
        $endDate = $params['end_date'];
        $userType = $params['user_type'] ?? 'all';

        // 获取活跃用户统计
        $activeUsers = $this->getActiveUsersStats($startDate, $endDate, $userType);

        // 获取投注行为分析
        $betBehavior = $this->getBetBehaviorAnalysis($startDate, $endDate, $userType);

        // 获取用户留存数据
        $retentionData = $this->getUserRetentionData($startDate, $endDate);

        // 获取大客户分析
        $highRollerAnalysis = $this->getHighRollerAnalysis($startDate, $endDate);

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'active_users' => $activeUsers,
                'bet_behavior' => $betBehavior,
                'retention_data' => $retentionData,
                'high_roller_analysis' => $highRollerAnalysis,
                'query_params' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'user_type' => $userType
                ]
            ]
        ]);
    }

    /**
     * ========================================
     * 系统配置管理相关方法
     * ========================================
     */

    /**
     * 获取赔率配置
     * 路由: GET /sicbo/admin/config/odds
     */
    public function getOddsConfig()
    {
        $odds = SicboOdds::getAllActiveOdds();
        
        // 按分类组织赔率数据
        $organizedOdds = [];
        foreach ($odds as $odd) {
            $category = $odd['bet_category'];
            if (!isset($organizedOdds[$category])) {
                $organizedOdds[$category] = [
                    'category_name' => SicboOdds::BET_CATEGORIES[$category] ?? $category,
                    'items' => []
                ];
            }
            $organizedOdds[$category]['items'][] = $odd;
        }

        // 获取分类统计
        $categoryStats = SicboOdds::getCategoryStats();

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'odds' => $organizedOdds,
                'category_stats' => $categoryStats,
                'total_count' => count($odds)
            ]
        ]);
    }

    /**
     * 更新赔率配置
     * 路由: PUT /sicbo/admin/config/odds
     */
    public function updateOddsConfig()
    {
        $params = $this->request->only(['bet_type', 'odds', 'min_bet', 'max_bet', 'status']);
        
        try {
            validate(SicboAdminValidate::class)->scene('update_odds')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $betType = $params['bet_type'];
        
        if (!SicboOdds::betTypeExists($betType)) {
            return json(['code' => 404, 'message' => '投注类型不存在']);
        }

        $updateData = [];
        if (isset($params['odds'])) {
            $updateData['odds'] = $params['odds'];
        }
        if (isset($params['min_bet'])) {
            $updateData['min_bet'] = $params['min_bet'];
        }
        if (isset($params['max_bet'])) {
            $updateData['max_bet'] = $params['max_bet'];
        }
        if (isset($params['status'])) {
            $updateData['status'] = $params['status'];
        }

        $result = SicboOdds::updateOdds($betType, $updateData);
        
        if ($result) {
            // 清除赔率缓存
            SicboOdds::clearOddsCache();
            
            return json([
                'code' => 200,
                'message' => '赔率配置更新成功',
                'data' => [
                    'bet_type' => $betType,
                    'updated_fields' => array_keys($updateData)
                ]
            ]);
        } else {
            return json(['code' => 500, 'message' => '赔率配置更新失败']);
        }
    }

    /**
     * 获取系统参数配置
     * 路由: GET /sicbo/admin/config/system
     */
    public function getSystemConfig()
    {
        // 获取系统配置（这里假设有一个系统配置表）
        $systemConfig = [
            'default_betting_time' => 30,
            'max_betting_time' => 120,
            'min_betting_time' => 10,
            'auto_settlement' => true,
            'max_bet_per_user' => 100000,
            'min_bet_global' => 10,
            'commission_rate' => 0.05,
            'max_win_per_game' => 1000000,
            'game_history_keep_days' => 90,
            'statistics_retention_days' => 365,
        ];

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'system_config' => $systemConfig,
                'update_time' => time()
            ]
        ]);
    }

    /**
     * 更新系统参数
     * 路由: PUT /sicbo/admin/config/system
     */
    public function updateSystemConfig()
    {
        $params = $this->request->only(['config_key', 'config_value', 'config_type']);
        
        try {
            validate(SicboAdminValidate::class)->scene('update_system_config')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $configKey = $params['config_key'];
        $configValue = $params['config_value'];
        $configType = $params['config_type'];

        // 这里应该更新到系统配置表
        // 暂时使用缓存模拟
        cache("sicbo:system_config:{$configKey}", $configValue, 3600 * 24);

        return json([
            'code' => 200,
            'message' => '系统配置更新成功',
            'data' => [
                'config_key' => $configKey,
                'config_value' => $configValue,
                'config_type' => $configType
            ]
        ]);
    }

    /**
     * ========================================
     * 私有辅助方法
     * ========================================
     */

    /**
     * 强制结束当前游戏
     */
    private function forceEndCurrentGame(int $tableId, string $reason, string $gameNumber = ''): bool
    {
        try {
            // 获取当前游戏信息
            $currentGame = cache("sicbo:current_game:{$tableId}");
            
            if ($currentGame) {
                $targetGameNumber = $gameNumber ?: $currentGame['game_number'];
                
                // 取消所有未结算的投注
                SicboBetRecords::where('game_number', $targetGameNumber)
                    ->where('settle_status', SicboBetRecords::SETTLE_STATUS_PENDING)
                    ->update([
                        'settle_status' => SicboBetRecords::SETTLE_STATUS_CANCELLED,
                        'settle_time' => date('Y-m-d H:i:s')
                    ]);

                // 退回用户投注金额（这里需要实现退款逻辑）
                $this->refundCancelledBets($targetGameNumber, $reason);
            }

            // 更新台桌状态
            Table::where('id', $tableId)->update([
                'run_status' => 0,
                'update_time' => time()
            ]);

            // 清除游戏缓存
            cache("sicbo:current_game:{$tableId}", null);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 退回被取消的投注金额
     */
    private function refundCancelledBets(string $gameNumber, string $reason): void
    {
        $cancelledBets = SicboBetRecords::where('game_number', $gameNumber)
            ->where('settle_status', SicboBetRecords::SETTLE_STATUS_CANCELLED)
            ->select();

        foreach ($cancelledBets as $bet) {
            // 退回金额到用户账户
            UserModel::where('id', $bet['user_id'])
                ->inc('money_balance', $bet['bet_amount'])
                ->update();
        }
    }

    /**
     * 记录荷官操作日志
     */
    private function logDealerAction(int $dealerId, int $tableId, string $action, array $data = []): void
    {
        // 这里应该记录到荷官操作日志表
        $logData = [
            'dealer_id' => $dealerId,
            'table_id' => $tableId,
            'action' => $action,
            'data' => json_encode($data),
            'ip_address' => $this->request->ip(),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // 暂时使用缓存记录
        $logKey = "sicbo:dealer_log:" . date('Ymd') . ":{$dealerId}:{$tableId}:" . microtime(true);
        cache($logKey, $logData, 3600 * 24 * 7);
    }

    /**
     * 获取台桌在线人数
     */
    private function getTableOnlineCount(int $tableId): int
    {
        // 这里应该从WebSocket连接记录或缓存中获取
        // 暂时返回模拟数据
        return cache("sicbo:online_count:{$tableId}") ?: rand(10, 100);
    }

    /**
     * 获取单个台桌监控数据
     */
    private function getSingleTableMonitor(int $tableId): array
    {
        $currentGame = cache("sicbo:current_game:{$tableId}");
        $todayStats = SicboStatistics::calculateTodayStats($tableId);
        $onlineCount = $this->getTableOnlineCount($tableId);
        
        // 获取当前局投注统计
        $currentBetStats = [];
        if ($currentGame) {
            $currentBetStats = SicboBetRecords::getGameBetStats($currentGame['game_number']);
        }

        return [
            'table_id' => $tableId,
            'current_game' => $currentGame,
            'today_stats' => $todayStats,
            'online_count' => $onlineCount,
            'current_bet_stats' => $currentBetStats,
            'last_update' => time()
        ];
    }

    /**
     * 获取全局监控数据
     */
    private function getGlobalMonitor(): array
    {
        $tables = Table::where('game_type', 9)->select();
        $globalStats = [
            'total_tables' => count($tables),
            'active_tables' => 0,
            'total_online_users' => 0,
            'total_today_rounds' => 0,
            'total_today_bets' => 0,
        ];

        foreach ($tables as $table) {
            if ($table->status == 1) {
                $globalStats['active_tables']++;
            }
            
            $globalStats['total_online_users'] += $this->getTableOnlineCount($table->id);
            
            $todayStats = SicboStatistics::calculateTodayStats($table->id);
            $globalStats['total_today_rounds'] += $todayStats['total_rounds'];
        }

        return $globalStats;
    }

    /**
     * 获取全局营收统计
     */
    private function getGlobalRevenueStats(string $startDate, string $endDate): array
    {
        $allStats = SicboStatistics::where('stat_type', SicboStatistics::STAT_TYPE_DAILY)
            ->whereBetweenTime('stat_date', $startDate, $endDate)
            ->field([
                'sum(total_bet_amount) as total_bet',
                'sum(total_win_amount) as total_payout',
                'sum(player_count) as total_players',
                'sum(total_rounds) as total_rounds'
            ])
            ->find();

        if (!$allStats) {
            return [
                'total_bet' => 0,
                'total_payout' => 0,
                'house_win' => 0,
                'house_edge' => 0,
                'total_players' => 0,
                'total_rounds' => 0,
            ];
        }

        $houseWin = $allStats['total_bet'] - $allStats['total_payout'];
        $houseEdge = $allStats['total_bet'] > 0 ? round($houseWin / $allStats['total_bet'] * 100, 2) : 0;

        return [
            'total_bet' => (float)$allStats['total_bet'],
            'total_payout' => (float)$allStats['total_payout'],
            'house_win' => $houseWin,
            'house_edge' => $houseEdge,
            'total_players' => (int)$allStats['total_players'],
            'total_rounds' => (int)$allStats['total_rounds'],
        ];
    }

    /**
     * 获取投注详情
     */
    private function getBetDetails(int $tableId, string $startDate, string $endDate): array
    {
        $query = SicboBetRecords::alias('br')
            ->whereBetweenTime('br.bet_time', $startDate . ' 00:00:00', $endDate . ' 23:59:59');

        if ($tableId > 0) {
            $query->where('br.table_id', $tableId);
        }

        return $query->field([
            'bet_type',
            'count(*) as bet_count',
            'sum(bet_amount) as total_amount',
            'sum(case when is_win = 1 then win_amount else 0 end) as total_win'
        ])
        ->group('bet_type')
        ->order('total_amount desc')
        ->select()
        ->toArray();
    }

    /**
     * 获取用户统计
     */
    private function getUserStats(int $tableId, string $startDate, string $endDate): array
    {
        $query = SicboBetRecords::alias('br')
            ->whereBetweenTime('br.bet_time', $startDate . ' 00:00:00', $endDate . ' 23:59:59');

        if ($tableId > 0) {
            $query->where('br.table_id', $tableId);
        }

        $stats = $query->field([
            'count(distinct user_id) as unique_users',
            'count(*) as total_bets',
            'avg(bet_amount) as avg_bet_amount',
            'max(bet_amount) as max_bet_amount'
        ])->find();

        return $stats ? $stats->toArray() : [
            'unique_users' => 0,
            'total_bets' => 0,
            'avg_bet_amount' => 0,
            'max_bet_amount' => 0
        ];
    }

    /**
     * 获取活跃用户统计
     */
    private function getActiveUsersStats(string $startDate, string $endDate, string $userType): array
    {
        // 实现活跃用户统计逻辑
        return [
            'daily_active_users' => 0,
            'new_users' => 0,
            'returning_users' => 0
        ];
    }

    /**
     * 获取投注行为分析
     */
    private function getBetBehaviorAnalysis(string $startDate, string $endDate, string $userType): array
    {
        // 实现投注行为分析逻辑
        return [
            'avg_session_duration' => 0,
            'avg_bets_per_session' => 0,
            'preferred_bet_types' => []
        ];
    }

    /**
     * 获取用户留存数据
     */
    private function getUserRetentionData(string $startDate, string $endDate): array
    {
        // 实现用户留存分析逻辑
        return [
            'day_1_retention' => 0,
            'day_7_retention' => 0,
            'day_30_retention' => 0
        ];
    }

    /**
     * 获取大客户分析
     */
    private function getHighRollerAnalysis(string $startDate, string $endDate): array
    {
        return SicboBetRecords::getHighRollerBets(10000, 20);
    }

    /**
     * 计算中奖投注类型
     */
    private function calculateWinningBets(int $dice1, int $dice2, int $dice3): array
    {
        $winningBets = [];
        $totalPoints = $dice1 + $dice2 + $dice3;
        $dices = [$dice1, $dice2, $dice3];
        sort($dices);

        // 大小
        if ($totalPoints >= 11 && $totalPoints <= 17) {
            $winningBets[] = 'big';
        } else {
            $winningBets[] = 'small';
        }

        // 单双
        if ($totalPoints % 2 === 1) {
            $winningBets[] = 'odd';
        } else {
            $winningBets[] = 'even';
        }

        // 总和
        $winningBets[] = "total-{$totalPoints}";

        // 单骰
        $diceCounts = array_count_values([$dice1, $dice2, $dice3]);
        foreach ($diceCounts as $dice => $count) {
            $winningBets[] = "single-{$dice}";
        }

        // 对子和三同号
        foreach ($diceCounts as $dice => $count) {
            if ($count === 2) {
                $winningBets[] = "pair-{$dice}";
            } elseif ($count === 3) {
                $winningBets[] = "triple-{$dice}";
                $winningBets[] = "any-triple";
            }
        }

        // 组合
        if (count(array_unique([$dice1, $dice2, $dice3])) >= 2) {
            $uniqueDices = array_unique([$dice1, $dice2, $dice3]);
            $uniqueDices = array_values($uniqueDices);
            sort($uniqueDices);
            
            for ($i = 0; $i < count($uniqueDices); $i++) {
                for ($j = $i + 1; $j < count($uniqueDices); $j++) {
                    $combo = "combo-{$uniqueDices[$i]}-{$uniqueDices[$j]}";
                    $winningBets[] = $combo;
                }
            }
        }

        return array_unique($winningBets);
    }
}