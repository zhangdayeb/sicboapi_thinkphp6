<?php
use think\facade\Route;

/**
 * ========================================
 * 骰宝游戏系统路由配置
 * ========================================
 * 基于实际控制器重新设计的路由配置
 * 版本：v3.0 - 基于实际代码优化版
 * 更新：根据实际控制器方法重新映射路由
 */

// ========================================
// 骰宝游戏路由组
// ========================================

/**
 * 骰宝游戏核心路由组
 * 前缀: /sicbo/game
 * 控制器: SicboGameController
 */
Route::group('sicbo/game', function () {
    
    /**
     * 台桌信息相关路由
     */
    // 获取台桌游戏信息
    Route::get('table-info', 'sicbo.SicboGameController/getTableInfo')
        ->name('sicbo_table_info');
    
    // 获取游戏历史记录
    Route::get('history', 'sicbo.SicboGameController/getGameHistory')
        ->name('sicbo_game_history');
    
    // 获取游戏统计数据
    Route::get('statistics', 'sicbo.SicboGameController/getStatistics')
        ->name('sicbo_game_statistics');
    
    /**
     * 游戏流程控制路由
     */
    // 开始新游戏局
    Route::post('start', 'sicbo.SicboGameController/startNewGame')
        ->name('sicbo_start_game');
    
    // 停止投注
    Route::post('stop-betting', 'sicbo.SicboGameController/stopBetting')
        ->name('sicbo_stop_betting');
    
    // 公布开奖结果
    Route::post('announce-result', 'sicbo.SicboGameController/announceResult')
        ->name('sicbo_announce_result');
    
    /**
     * 实时数据获取路由
     */
    // 获取当前投注统计
    Route::get('bet-stats', 'sicbo.SicboGameController/getCurrentBetStats')
        ->name('sicbo_current_bet_stats');
    
    // 获取赔率信息
    Route::get('odds', 'sicbo.SicboGameController/getOddsInfo')
        ->name('sicbo_odds_info');

})->middleware(['Auth', 'ApiLog']); // 需要用户认证和API日志记录

// ========================================
// 骰宝投注路由组
// 前缀: /sicbo/bet
// 控制器: SicboBetController
// ========================================

Route::group('sicbo/bet', function () {
    
    /**
     * 用户投注操作路由
     */
    // 提交用户投注
    Route::post('place', 'sicbo.SicboBetController/placeBet')
        ->name('sicbo_place_bet');
    
    // 修改当前投注
    Route::put('modify', 'sicbo.SicboBetController/modifyBet')
        ->name('sicbo_modify_bet');
    
    // 取消当前投注
    Route::delete('cancel', 'sicbo.SicboBetController/cancelBet')
        ->name('sicbo_cancel_bet');
    
    /**
     * 投注记录查询路由
     */
    // 获取用户当前投注
    Route::get('current', 'sicbo.SicboBetController/getCurrentBets')
        ->name('sicbo_current_bets');
    
    // 获取用户投注历史
    Route::get('history', 'sicbo.SicboBetController/getBetHistory')
        ->name('sicbo_bet_history');
    
    // 获取投注详情
    Route::get('detail/:bet_id', 'sicbo.SicboBetController/getBetDetail')
        ->pattern(['bet_id' => '\d+'])
        ->name('sicbo_bet_detail');
    
    /**
     * 余额和限额管理路由
     */
    // 获取用户余额信息
    Route::get('balance', 'sicbo.SicboBetController/getUserBalance')
        ->name('sicbo_user_balance');
    
    // 获取投注限额信息
    Route::get('limits', 'sicbo.SicboBetController/getBetLimits')
        ->name('sicbo_bet_limits');
    
    // 预检投注合法性
    Route::post('validate', 'sicbo.SicboBetController/validateBet')
        ->name('sicbo_validate_bet');

})->middleware(['Auth', 'UserStatus', 'ApiLog']); // 需要用户认证、状态检查和日志

// ========================================
// 骰宝管理后台路由组
// 前缀: /sicbo/admin
// 控制器: SicboAdminController
// ========================================

Route::group('sicbo/admin', function () {
    
    /**
     * 台桌管理路由
     */
    // 获取台桌列表（管理视图）
    Route::get('tables', 'sicbo.SicboAdminController/getTableList')
        ->name('sicbo_admin_tables');
    
    // 更新台桌设置
    Route::put('table/:table_id', 'sicbo.SicboAdminController/updateTableConfig')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_admin_update_table');
    
    // 台桌开关控制
    Route::post('table/:table_id/toggle', 'sicbo.SicboAdminController/toggleTableStatus')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_admin_toggle_table');
    
    /**
     * 荷官操作功能路由
     */
    // 荷官开始游戏
    Route::post('dealer/start-game', 'sicbo.SicboAdminController/dealerStartGame')
        ->name('sicbo_dealer_start_game');
    
    // 荷官录入开奖结果
    Route::post('dealer/input-result', 'sicbo.SicboAdminController/dealerInputResult')
        ->name('sicbo_dealer_input_result');
    
    // 荷官强制结束游戏
    Route::post('dealer/force-end', 'sicbo.SicboAdminController/dealerForceEnd')
        ->name('sicbo_dealer_force_end');
    
    /**
     * 数据监控和报表路由
     */
    // 获取实时监控数据
    Route::get('monitor/realtime', 'sicbo.SicboAdminController/getRealtimeMonitor')
        ->name('sicbo_admin_realtime_monitor');
    
    // 获取财务报表
    Route::get('report/financial', 'sicbo.SicboAdminController/getFinancialReport')
        ->name('sicbo_admin_financial_report');
    
    // 获取用户行为分析
    Route::get('report/user-behavior', 'sicbo.SicboAdminController/getUserBehaviorReport')
        ->name('sicbo_admin_user_behavior');
    
    /**
     * 系统配置管理路由
     */
    // 获取赔率配置
    Route::get('config/odds', 'sicbo.SicboAdminController/getOddsConfig')
        ->name('sicbo_admin_odds_config');
    
    // 更新赔率配置
    Route::put('config/odds', 'sicbo.SicboAdminController/updateOddsConfig')
        ->name('sicbo_admin_update_odds');
    
    // 获取系统参数配置
    Route::get('config/system', 'sicbo.SicboAdminController/getSystemConfig')
        ->name('sicbo_admin_system_config');
    
    // 更新系统参数
    Route::put('config/system', 'sicbo.SicboAdminController/updateSystemConfig')
        ->name('sicbo_admin_update_system');

})->middleware(['Auth', 'AdminAuth', 'ApiLog']); // 需要管理员权限

// ========================================
// 骰宝API接口路由组
// 前缀: /api/sicbo
// 控制器: SicboApiController
// ========================================

Route::group('api/sicbo', function () {
    
    /**
     * 基础API接口路由
     */
    // API身份验证
    Route::post('auth', 'sicbo.SicboApiController/authenticate')
        ->name('sicbo_api_auth');
    
    // 获取台桌列表API
    Route::get('tables', 'sicbo.SicboApiController/apiGetTables')
        ->name('sicbo_api_tables');
    
    // 获取游戏状态API
    Route::get('game-status/:table_id', 'sicbo.SicboApiController/apiGetGameStatus')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_api_game_status');
    
    /**
     * 投注相关API路由
     */
    // API投注接口
    Route::post('bet', 'sicbo.SicboApiController/apiBet')
        ->name('sicbo_api_bet');
    
    // 查询投注结果API
    Route::get('bet-result/:game_number', 'sicbo.SicboApiController/apiGetBetResult')
        ->pattern(['game_number' => '[\w\-]+'])
        ->name('sicbo_api_bet_result');
    
    // 获取用户余额API
    Route::get('balance', 'sicbo.SicboApiController/apiGetBalance')
        ->name('sicbo_api_balance');
    
    /**
     * 数据查询API路由
     */
    // 获取开奖历史API
    Route::get('results', 'sicbo.SicboApiController/apiGetResults')
        ->name('sicbo_api_results');
    
    // 获取赔率信息API
    Route::get('odds/:table_id', 'sicbo.SicboApiController/apiGetOdds')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_api_odds');
    
    // 获取统计数据API
    Route::get('statistics/:table_id', 'sicbo.SicboApiController/apiGetStatistics')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_api_statistics');
    
    /**
     * 移动端专用接口路由
     */
    // 移动端快速投注
    Route::post('mobile/quick-bet', 'sicbo.SicboApiController/mobileQuickBet')
        ->name('sicbo_api_mobile_quick_bet');
    
    // 移动端游戏状态推送注册
    Route::post('mobile/subscribe', 'sicbo.SicboApiController/mobileSubscribe')
        ->name('sicbo_api_mobile_subscribe');
    
    // 移动端用户偏好设置
    Route::put('mobile/preferences', 'sicbo.SicboApiController/mobileSetPreferences')
        ->name('sicbo_api_mobile_preferences');

})->middleware(['ApiAuth', 'ApiLimit', 'ApiLog']); // API认证、频率限制和日志

// ========================================
// 系统测试和首页路由
// 前缀: /
// 控制器: Index
// ========================================

// 首页路由
Route::get('/', 'Index/index')
    ->name('homepage');

/**
 * 测试相关路由组
 * 前缀: /test
 */
Route::group('test', function () {
    
    // 骰宝系统完整测试
    Route::get('sicbo/full', 'Index/testSicboSystemFull')
        ->name('test_sicbo_full');
    
    // 快速健康检查
    Route::get('health', 'Index/quickHealthCheck')
        ->name('test_health_check');
    
    // 清理测试数据
    Route::post('cleanup', 'Index/cleanupTestData')
        ->name('test_cleanup');

});

// ========================================
// 系统基础路由
// ========================================

// 健康检查接口
Route::get('health', function() {
    return json([
        'status' => 'ok',
        'timestamp' => time(),
        'version' => '3.0.0',
        'services' => [
            'sicbo' => 'active',
            'database' => 'connected',
            'redis' => 'connected',
            'websocket' => 'running'
        ]
    ]);
})->name('health_check');

// 系统信息接口
Route::get('info', function() {
    return json([
        'system' => 'Sicbo Game System',
        'version' => '3.0.0',
        'api_version' => '1.0',
        'timestamp' => time(),
        'timezone' => 'Asia/Shanghai',
        'modules' => [
            'sicbo_game' => 'enabled',
            'sicbo_bet' => 'enabled',
            'sicbo_admin' => 'enabled',
            'sicbo_api' => 'enabled'
        ]
    ]);
})->name('system_info');

// ========================================
// 错误处理路由
// ========================================

// 404 处理
Route::miss(function() {
    return json([
        'code' => 404,
        'message' => 'API接口不存在',
        'data' => null,
        'timestamp' => time()
    ], 404);
});

/**
 * ========================================
 * 路由配置说明
 * ========================================
 * 
 * 根据实际控制器方法重新设计的路由配置：
 * 
 * 1. SicboGameController 实际方法：
 *    - getTableInfo() - 获取台桌游戏信息
 *    - getGameHistory() - 获取游戏历史记录
 *    - getStatistics() - 获取游戏统计数据
 *    - startNewGame() - 开始新游戏局
 *    - stopBetting() - 停止投注
 *    - announceResult() - 公布开奖结果
 *    - getCurrentBetStats() - 获取当前投注统计
 *    - getOddsInfo() - 获取赔率信息
 * 
 * 2. SicboBetController 实际方法：
 *    - placeBet() - 提交用户投注
 *    - modifyBet() - 修改当前投注
 *    - cancelBet() - 取消当前投注
 *    - getCurrentBets() - 获取用户当前投注
 *    - getBetHistory() - 获取用户投注历史
 *    - getBetDetail() - 获取投注详情
 *    - getUserBalance() - 获取用户余额信息
 *    - getBetLimits() - 获取投注限额信息
 *    - validateBet() - 预检投注合法性
 * 
 * 3. SicboAdminController 实际方法：
 *    - getTableList() - 获取台桌列表
 *    - updateTableConfig() - 更新台桌设置
 *    - toggleTableStatus() - 台桌开关控制
 *    - dealerStartGame() - 荷官开始游戏
 *    - dealerInputResult() - 荷官录入开奖结果
 *    - dealerForceEnd() - 荷官强制结束游戏
 *    - getRealtimeMonitor() - 获取实时监控数据
 *    - getFinancialReport() - 获取财务报表
 *    - getUserBehaviorReport() - 获取用户行为分析
 *    - getOddsConfig() - 获取赔率配置
 *    - updateOddsConfig() - 更新赔率配置
 *    - getSystemConfig() - 获取系统参数配置
 *    - updateSystemConfig() - 更新系统参数
 * 
 * 4. SicboApiController 实际方法：
 *    - authenticate() - API身份验证
 *    - apiGetTables() - 获取台桌列表API
 *    - apiGetGameStatus() - 获取游戏状态API
 *    - apiBet() - API投注接口
 *    - apiGetBetResult() - 查询投注结果API
 *    - apiGetBalance() - 获取用户余额API
 *    - apiGetResults() - 获取开奖历史API
 *    - apiGetOdds() - 获取赔率信息API
 *    - apiGetStatistics() - 获取统计数据API
 *    - mobileQuickBet() - 移动端快速投注
 *    - mobileSubscribe() - 移动端游戏状态推送注册
 *    - mobileSetPreferences() - 移动端用户偏好设置
 * 
 * 5. Index 控制器实际方法：
 *    - index() - 首页
 *    - testSicboSystemFull() - 骰宝系统完整测试
 *    - quickHealthCheck() - 快速健康检查
 *    - cleanupTestData() - 清理测试数据
 * 
 * 特点：
 * - 严格按照实际控制器方法映射路由
 * - 移除了不存在的方法对应的路由
 * - 保持 RESTful API 设计原则
 * - 合理的路由分组和命名
 * - 适当的中间件配置
 * - 清晰的参数验证规则
 * 
 * 中间件说明：
 * - Auth: 用户身份认证中间件
 * - AdminAuth: 管理员权限认证中间件  
 * - UserStatus: 用户状态检查中间件
 * - ApiAuth: API接口认证中间件
 * - ApiLimit: API频率限制中间件
 * - ApiLog: API日志记录中间件
 * 
 * 参数验证：
 * - table_id: 台桌ID，必须是数字
 * - bet_id: 投注ID，必须是数字
 * - game_number: 游戏局号，支持字母数字和连字符
 */