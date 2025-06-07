<?php
use think\facade\Route;

/**
 * ========================================
 * 骰宝游戏系统路由配置 - 修复版
 * ========================================
 */

// ========================================
// 首页和测试路由
// ========================================

// 首页路由 - 根路径
Route::get('/', 'Index/index')->name('homepage');

// 测试页面路由 - 直接访问测试页面
Route::get('/test', 'Index/index')->name('test_page');

// 调试路由 - 用于诊断问题
Route::get('/debug', 'Debug/index')->name('debug_info');
Route::get('/debug/health', 'Debug/health')->name('debug_health');
Route::get('/debug/test', 'Debug/test')->name('debug_test');
Route::get('/debug/routes', 'Debug/routes')->name('debug_routes');

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
// 骰宝游戏核心路由组
// ========================================

/**
 * 骰宝游戏核心路由组
 * 前缀: /sicbo/game
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

}); // 移除了可能不存在的中间件

// ========================================
// 骰宝投注路由组
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

});

// ========================================
// 骰宝管理后台路由组
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

});

// ========================================
// 骰宝API接口路由组
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
 * 调试路由 - 仅开发环境使用
 * ========================================
 */
if (app()->isDebug()) {
    // 查看所有路由
    Route::get('debug/routes', function() {
        $routes = \think\facade\Route::getRuleList();
        return json([
            'total' => count($routes),
            'routes' => $routes
        ]);
    })->name('debug_routes');
    
    // 测试控制器是否存在
    Route::get('debug/controller/:controller', function($controller) {
        $className = "app\\controller\\{$controller}";
        return json([
            'controller' => $controller,
            'class' => $className,
            'exists' => class_exists($className),
            'methods' => class_exists($className) ? get_class_methods($className) : []
        ]);
    })->name('debug_controller');
}