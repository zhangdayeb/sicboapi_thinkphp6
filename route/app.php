<?php
use think\facade\Route;

/**
 * ========================================
 * 骰宝游戏系统路由配置
 * ========================================
 * 专注于：骰宝游戏、用户管理、系统管理
 * 移除了：百家乐、支付系统相关路由
 * 版本：v2.1 - 精简版
 * 更新：移除不需要的路由，专注骰宝游戏
 */

// ========================================
// 骰宝游戏路由组
// ========================================

/**
 * 骰宝游戏核心路由组
 * 前缀: /sicbo
 */
Route::group('sicbo', function () {
    
    // ========================================
    // 游戏主控制器路由 - SicboGameController
    // ========================================
    
    /**
     * 台桌信息相关路由
     */
    // 获取台桌列表
    Route::get('tables', 'sicbo.SicboGame/getTableList')
        ->name('sicbo_table_list');
    
    // 获取指定台桌信息
    Route::get('table/:table_id/info', 'sicbo.SicboGame/getTableInfo')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_table_info');
    
    // 获取台桌当前游戏状态
    Route::get('table/:table_id/status', 'sicbo.SicboGame/getGameStatus')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_game_status');
    
    // 获取台桌视频流信息
    Route::get('table/:table_id/video', 'sicbo.SicboGame/getTableVideo')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_table_video');
    
    /**
     * 游戏历史和统计路由
     */
    // 获取游戏历史记录
    Route::get('table/:table_id/history', 'sicbo.SicboGame/getGameHistory')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_game_history');
    
    // 获取游戏统计数据
    Route::get('table/:table_id/statistics', 'sicbo.SicboGame/getStatistics')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_game_statistics');
    
    // 获取实时统计数据
    Route::get('table/:table_id/stats/realtime', 'sicbo.SicboGame/getRealtimeStats')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_realtime_stats');
    
    // 获取趋势分析数据
    Route::get('table/:table_id/trends', 'sicbo.SicboGame/getTrendData')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_trend_data');
    
    /**
     * 游戏流程控制路由
     */
    // 开始新游戏局
    Route::post('table/:table_id/start', 'sicbo.SicboGame/startNewGame')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_start_game');
    
    // 停止投注
    Route::post('table/:table_id/stop-betting', 'sicbo.SicboGame/stopBetting')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_stop_betting');
    
    // 公布开奖结果
    Route::post('table/:table_id/announce-result', 'sicbo.SicboGame/announceResult')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_announce_result');
    
    /**
     * 实时数据获取路由
     */
    // 获取当前投注统计
    Route::get('table/:table_id/game/:game_number/bet-stats', 'sicbo.SicboGame/getCurrentBetStats')
        ->pattern(['table_id' => '\d+', 'game_number' => '[\w\-]+'])
        ->name('sicbo_current_bet_stats');
    
    // 获取赔率信息
    Route::get('odds', 'sicbo.SicboGame/getOddsInfo')
        ->name('sicbo_odds_info');
    
    // 获取指定台桌赔率
    Route::get('table/:table_id/odds', 'sicbo.SicboGame/getTableOdds')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_table_odds');

})->middleware(['Auth', 'ApiLog']); // 需要用户认证和API日志记录

// ========================================
// 骰宝投注路由组 - SicboBetController
// ========================================

Route::group('sicbo/bet', function () {
    
    /**
     * 用户投注操作路由
     */
    // 提交用户投注
    Route::post('place', 'sicbo.SicboBet/placeBet')
        ->name('sicbo_place_bet');
    
    // 修改当前投注
    Route::put('modify', 'sicbo.SicboBet/modifyBet')
        ->name('sicbo_modify_bet');
    
    // 取消当前投注
    Route::delete('cancel', 'sicbo.SicboBet/cancelBet')
        ->name('sicbo_cancel_bet');
    
    // 快速投注（重复上次投注）
    Route::post('quick', 'sicbo.SicboBet/quickBet')
        ->name('sicbo_quick_bet');
    
    /**
     * 投注记录查询路由
     */
    // 获取用户当前投注
    Route::get('current', 'sicbo.SicboBet/getCurrentBets')
        ->name('sicbo_current_bets');
    
    // 获取用户投注历史
    Route::get('history', 'sicbo.SicboBet/getBetHistory')
        ->name('sicbo_bet_history');
    
    // 获取投注详情
    Route::get('detail/:bet_id', 'sicbo.SicboBet/getBetDetail')
        ->pattern(['bet_id' => '\d+'])
        ->name('sicbo_bet_detail');
    
    // 获取指定游戏的投注记录
    Route::get('game/:game_number', 'sicbo.SicboBet/getGameBets')
        ->pattern(['game_number' => '[\w\-]+'])
        ->name('sicbo_game_bets');
    
    /**
     * 余额和限额管理路由
     */
    // 获取用户余额信息
    Route::get('balance', 'sicbo.SicboBet/getUserBalance')
        ->name('sicbo_user_balance');
    
    // 获取投注限额信息
    Route::get('limits', 'sicbo.SicboBet/getBetLimits')
        ->name('sicbo_bet_limits');
    
    // 预检投注合法性
    Route::post('validate', 'sicbo.SicboBet/validateBet')
        ->name('sicbo_validate_bet');
    
    /**
     * 投注统计路由
     */
    // 获取个人投注统计
    Route::get('stats/personal', 'sicbo.SicboBet/getPersonalStats')
        ->name('sicbo_personal_bet_stats');
    
    // 获取投注偏好分析
    Route::get('stats/preferences', 'sicbo.SicboBet/getBetPreferences')
        ->name('sicbo_bet_preferences');

})->middleware(['Auth', 'UserStatus', 'ApiLog']); // 需要用户认证、状态检查和日志

// ========================================
// 骰宝管理后台路由组 - SicboAdminController
// ========================================

Route::group('sicbo/admin', function () {
    
    /**
     * 台桌管理路由
     */
    // 获取台桌列表（管理视图）
    Route::get('tables', 'sicbo.SicboAdmin/getTableList')
        ->name('sicbo_admin_tables');
    
    // 创建新台桌
    Route::post('table/create', 'sicbo.SicboAdmin/createTable')
        ->name('sicbo_admin_create_table');
    
    // 更新台桌设置
    Route::put('table/:table_id', 'sicbo.SicboAdmin/updateTableConfig')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_admin_update_table');
    
    // 台桌开关控制
    Route::post('table/:table_id/toggle', 'sicbo.SicboAdmin/toggleTableStatus')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_admin_toggle_table');
    
    // 删除台桌
    Route::delete('table/:table_id', 'sicbo.SicboAdmin/deleteTable')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_admin_delete_table');
    
    /**
     * 荷官操作功能路由
     */
    // 荷官开始游戏
    Route::post('dealer/start-game', 'sicbo.SicboAdmin/dealerStartGame')
        ->name('sicbo_dealer_start_game');
    
    // 荷官录入开奖结果
    Route::post('dealer/input-result', 'sicbo.SicboAdmin/dealerInputResult')
        ->name('sicbo_dealer_input_result');
    
    // 荷官强制结束游戏
    Route::post('dealer/force-end', 'sicbo.SicboAdmin/dealerForceEnd')
        ->name('sicbo_dealer_force_end');
    
    // 荷官暂停/恢复游戏
    Route::post('dealer/pause', 'sicbo.SicboAdmin/dealerPauseGame')
        ->name('sicbo_dealer_pause');
    
    // 荷官取消游戏局
    Route::post('dealer/cancel-game', 'sicbo.SicboAdmin/dealerCancelGame')
        ->name('sicbo_dealer_cancel_game');
    
    /**
     * 数据监控和报表路由
     */
    // 获取实时监控数据
    Route::get('monitor/realtime', 'sicbo.SicboAdmin/getRealtimeMonitor')
        ->name('sicbo_admin_realtime_monitor');
    
    // 获取财务报表
    Route::get('report/financial', 'sicbo.SicboAdmin/getFinancialReport')
        ->name('sicbo_admin_financial_report');
    
    // 获取用户行为分析
    Route::get('report/user-behavior', 'sicbo.SicboAdmin/getUserBehaviorReport')
        ->name('sicbo_admin_user_behavior');
    
    // 获取游戏数据报表
    Route::get('report/game-data', 'sicbo.SicboAdmin/getGameDataReport')
        ->name('sicbo_admin_game_report');
    
    // 获取台桌性能报表
    Route::get('report/table-performance', 'sicbo.SicboAdmin/getTablePerformanceReport')
        ->name('sicbo_admin_table_performance');
    
    /**
     * 系统配置管理路由
     */
    // 获取赔率配置
    Route::get('config/odds', 'sicbo.SicboAdmin/getOddsConfig')
        ->name('sicbo_admin_odds_config');
    
    // 更新赔率配置
    Route::put('config/odds', 'sicbo.SicboAdmin/updateOddsConfig')
        ->name('sicbo_admin_update_odds');
    
    // 批量更新赔率
    Route::post('config/odds/batch', 'sicbo.SicboAdmin/batchUpdateOdds')
        ->name('sicbo_admin_batch_odds');
    
    // 获取系统参数配置
    Route::get('config/system', 'sicbo.SicboAdmin/getSystemConfig')
        ->name('sicbo_admin_system_config');
    
    // 更新系统参数
    Route::put('config/system', 'sicbo.SicboAdmin/updateSystemConfig')
        ->name('sicbo_admin_update_system');
    
    /**
     * 用户管理路由
     */
    // 获取用户列表
    Route::get('users', 'sicbo.SicboAdmin/getUserList')
        ->name('sicbo_admin_users');
    
    // 获取用户详情
    Route::get('user/:user_id', 'sicbo.SicboAdmin/getUserDetail')
        ->pattern(['user_id' => '\d+'])
        ->name('sicbo_admin_user_detail');
    
    // 用户投注限制设置
    Route::post('user/:user_id/limit', 'sicbo.SicboAdmin/setUserLimit')
        ->pattern(['user_id' => '\d+'])
        ->name('sicbo_admin_user_limit');
    
    // 用户状态管理
    Route::post('user/:user_id/status', 'sicbo.SicboAdmin/updateUserStatus')
        ->pattern(['user_id' => '\d+'])
        ->name('sicbo_admin_user_status');
    
    /**
     * 风控管理路由
     */
    // 获取风控规则
    Route::get('risk/rules', 'sicbo.SicboAdmin/getRiskRules')
        ->name('sicbo_admin_risk_rules');
    
    // 更新风控规则
    Route::put('risk/rules', 'sicbo.SicboAdmin/updateRiskRules')
        ->name('sicbo_admin_update_risk_rules');
    
    // 获取风控警报
    Route::get('risk/alerts', 'sicbo.SicboAdmin/getRiskAlerts')
        ->name('sicbo_admin_risk_alerts');
    
    // 处理风控警报
    Route::post('risk/alert/:alert_id/handle', 'sicbo.SicboAdmin/handleRiskAlert')
        ->pattern(['alert_id' => '\d+'])
        ->name('sicbo_admin_handle_alert');
    
    /**
     * 系统维护路由
     */
    // 数据统计更新
    Route::post('maintenance/update-stats', 'sicbo.SicboAdmin/updateStatistics')
        ->name('sicbo_admin_update_stats');
    
    // 清理缓存
    Route::post('maintenance/clear-cache', 'sicbo.SicboAdmin/clearCache')
        ->name('sicbo_admin_clear_cache');
    
    // 重建统计数据
    Route::post('maintenance/rebuild-stats', 'sicbo.SicboAdmin/rebuildStatistics')
        ->name('sicbo_admin_rebuild_stats');
    
    // 数据备份
    Route::post('maintenance/backup', 'sicbo.SicboAdmin/backupData')
        ->name('sicbo_admin_backup');

})->middleware(['Auth', 'AdminAuth', 'ApiLog']); // 需要管理员权限

// ========================================
// 骰宝API接口路由组 - SicboApiController
// ========================================

Route::group('api/sicbo', function () {
    
    /**
     * 基础API接口路由
     */
    // API身份验证
    Route::post('auth', 'sicbo.SicboApi/authenticate')
        ->name('sicbo_api_auth');
    
    // 获取台桌列表API
    Route::get('tables', 'sicbo.SicboApi/apiGetTables')
        ->name('sicbo_api_tables');
    
    // 获取游戏状态API
    Route::get('game-status/:table_id', 'sicbo.SicboApi/apiGetGameStatus')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_api_game_status');
    
    // 获取台桌信息API
    Route::get('table/:table_id/info', 'sicbo.SicboApi/apiGetTableInfo')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_api_table_info');
    
    /**
     * 投注相关API路由
     */
    // API投注接口
    Route::post('bet', 'sicbo.SicboApi/apiBet')
        ->name('sicbo_api_bet');
    
    // 查询投注结果API
    Route::get('bet-result/:game_number', 'sicbo.SicboApi/apiGetBetResult')
        ->pattern(['game_number' => '[\w\-]+'])
        ->name('sicbo_api_bet_result');
    
    // 获取用户余额API
    Route::get('balance', 'sicbo.SicboApi/apiGetBalance')
        ->name('sicbo_api_balance');
    
    // 获取投注历史API
    Route::get('bet-history', 'sicbo.SicboApi/apiGetBetHistory')
        ->name('sicbo_api_bet_history');
    
    /**
     * 数据查询API路由
     */
    // 获取开奖历史API
    Route::get('results', 'sicbo.SicboApi/apiGetResults')
        ->name('sicbo_api_results');
    
    // 获取指定台桌历史
    Route::get('table/:table_id/results', 'sicbo.SicboApi/apiGetTableResults')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_api_table_results');
    
    // 获取赔率信息API
    Route::get('odds/:table_id', 'sicbo.SicboApi/apiGetOdds')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_api_odds');
    
    // 获取统计数据API
    Route::get('statistics/:table_id', 'sicbo.SicboApi/apiGetStatistics')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_api_statistics');
    
    /**
     * 移动端专用接口路由
     */
    // 移动端快速投注
    Route::post('mobile/quick-bet', 'sicbo.SicboApi/mobileQuickBet')
        ->name('sicbo_api_mobile_quick_bet');
    
    // 移动端游戏状态推送注册
    Route::post('mobile/subscribe', 'sicbo.SicboApi/mobileSubscribe')
        ->name('sicbo_api_mobile_subscribe');
    
    // 移动端用户偏好设置
    Route::put('mobile/preferences', 'sicbo.SicboApi/mobileSetPreferences')
        ->name('sicbo_api_mobile_preferences');
    
    // 移动端获取推荐投注
    Route::get('mobile/recommendations', 'sicbo.SicboApi/mobileGetRecommendations')
        ->name('sicbo_api_mobile_recommendations');

})->middleware(['ApiAuth', 'ApiLimit', 'ApiLog']); // API认证、频率限制和日志

// ========================================
// 用户中心路由
// ========================================

Route::group('user', function () {
    
    // 用户信息
    Route::get('profile', 'user.User/getProfile')
        ->name('user_profile');
    
    // 更新用户信息
    Route::put('profile', 'user.User/updateProfile')
        ->name('user_update_profile');
    
    // 修改密码
    Route::post('change-password', 'user.User/changePassword')
        ->name('user_change_password');
    
    // 获取余额信息
    Route::get('balance', 'user.User/getBalance')
        ->name('user_balance');
    
    // 获取余额变动记录
    Route::get('balance/logs', 'user.User/getBalanceLogs')
        ->name('user_balance_logs');
    
    // 获取投注记录
    Route::get('bets', 'user.User/getBetRecords')
        ->name('user_bet_records');
    
    // 获取游戏统计
    Route::get('game-stats', 'user.User/getGameStats')
        ->name('user_game_stats');

})->middleware(['Auth', 'UserStatus']);

// ========================================
// 认证相关路由
// ========================================

Route::group('auth', function () {
    
    // 用户登录
    Route::post('login', 'auth.Auth/login')
        ->name('user_login');
    
    // 用户注册
    Route::post('register', 'auth.Auth/register')
        ->name('user_register');
    
    // 用户登出
    Route::post('logout', 'auth.Auth/logout')
        ->name('user_logout');
    
    // 刷新Token
    Route::post('refresh', 'auth.Auth/refreshToken')
        ->name('refresh_token');
    
    // 忘记密码
    Route::post('forgot-password', 'auth.Auth/forgotPassword')
        ->name('forgot_password');
    
    // 重置密码
    Route::post('reset-password', 'auth.Auth/resetPassword')
        ->name('reset_password');
    
    // 发送验证码
    Route::post('send-code', 'auth.Auth/sendVerificationCode')
        ->name('send_verification_code');
    
    // 验证验证码
    Route::post('verify-code', 'auth.Auth/verifyCode')
        ->name('verify_code');

})->middleware(['ApiLimit']);

// ========================================
// 配置和设置路由
// ========================================

Route::group('config', function () {
    
    // 获取系统配置
    Route::get('system', 'config.Config/getSystemConfig')
        ->name('system_config');
    
    // 获取游戏配置
    Route::get('game', 'config.Config/getGameConfig')
        ->name('game_config');
    
    // 获取客服配置
    Route::get('service', 'config.Config/getServiceConfig')
        ->name('service_config');
    
    // 获取公告列表
    Route::get('announcements', 'config.Config/getAnnouncements')
        ->name('announcements');
    
    // 获取帮助文档
    Route::get('help', 'config.Config/getHelpDocs')
        ->name('help_docs');

});

// ========================================
// 文件上传和资源路由
// ========================================

Route::group('upload', function () {
    
    // 图片上传
    Route::post('image', 'common.Upload/uploadImage')
        ->name('upload_image');
    
    // 视频上传
    Route::post('video', 'common.Upload/uploadVideo')
        ->name('upload_video');
    
    // 头像上传
    Route::post('avatar', 'common.Upload/uploadAvatar')
        ->name('upload_avatar');

})->middleware(['Auth', 'UploadLimit']);

// ========================================
// 统计和分析路由 (管理员)
// ========================================

Route::group('analytics', function () {
    
    // 平台总览数据
    Route::get('overview', 'analytics.Analytics/getOverview')
        ->name('analytics_overview');
    
    // 游戏数据分析
    Route::get('games', 'analytics.Analytics/getGameAnalytics')
        ->name('analytics_games');
    
    // 用户行为分析
    Route::get('users', 'analytics.Analytics/getUserAnalytics')
        ->name('analytics_users');
    
    // 财务数据分析
    Route::get('financial', 'analytics.Analytics/getFinancialAnalytics')
        ->name('analytics_financial');
    
    // 风控数据分析
    Route::get('risk', 'analytics.Analytics/getRiskAnalytics')
        ->name('analytics_risk');

})->middleware(['Auth', 'AdminAuth']);

// ========================================
// 系统维护路由 (超级管理员)
// ========================================

Route::group('maintenance', function () {
    
    // 系统状态检查
    Route::get('status', 'maintenance.Maintenance/getSystemStatus')
        ->name('maintenance_status');
    
    // 清理系统缓存
    Route::post('clear-cache', 'maintenance.Maintenance/clearCache')
        ->name('maintenance_clear_cache');
    
    // 重建数据索引
    Route::post('rebuild-index', 'maintenance.Maintenance/rebuildIndex')
        ->name('maintenance_rebuild_index');
    
    // 数据备份
    Route::post('backup', 'maintenance.Maintenance/backupData')
        ->name('maintenance_backup');
    
    // 数据恢复
    Route::post('restore', 'maintenance.Maintenance/restoreData')
        ->name('maintenance_restore');
    
    // 系统日志查看
    Route::get('logs', 'maintenance.Maintenance/getSystemLogs')
        ->name('maintenance_logs');

})->middleware(['Auth', 'SuperAdminAuth']);

// ========================================
// WebSocket 连接路由
// ========================================

Route::group('ws', function () {
    
    // 骰宝WebSocket连接
    Route::get('sicbo/:table_id', 'websocket.SicboWebSocket/connect')
        ->pattern(['table_id' => '\d+'])
        ->name('sicbo_websocket');
    
    // 系统通知WebSocket
    Route::get('notifications', 'websocket.NotificationWebSocket/connect')
        ->name('notification_websocket');

})->middleware(['WebSocketAuth']);

// ========================================
// 系统基础路由
// ========================================

// 首页路由
Route::rule('/$', 'index/index', 'GET')
    ->name('homepage');

// 健康检查
Route::get('health', function() {
    return json([
        'status' => 'ok',
        'timestamp' => time(),
        'version' => '2.1.0',
        'services' => [
            'sicbo' => 'active',
            'database' => 'connected',
            'redis' => 'connected',
            'websocket' => 'running'
        ]
    ]);
})->name('health_check');

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
 * 路由说明文档
 * ========================================
 * 
 * 已移除的路由：
 * - 百家乐游戏相关路由（bjl/*）
 * - 支付系统相关路由（payment/*）
 * - 百家乐露珠数据路由
 * - 百家乐荷官操作路由
 * - 百家乐用户投注路由
 * 
 * 保留的功能：
 * - 完整的骰宝游戏系统
 * - 用户认证和管理
 * - 系统配置和维护
 * - 文件上传功能
 * - 统计分析功能
 * - WebSocket通信
 * 
 * 中间件说明：
 * - Auth: 用户身份认证中间件
 * - AdminAuth: 管理员权限认证中间件  
 * - SuperAdminAuth: 超级管理员权限认证中间件
 * - UserStatus: 用户状态检查中间件
 * - ApiAuth: API接口认证中间件
 * - ApiLimit: API频率限制中间件
 * - ApiLog: API日志记录中间件
 * - WebSocketAuth: WebSocket连接认证中间件
 * - UploadLimit: 文件上传限制中间件
 * 
 * 路由命名规范：
 * - 骰宝相关: sicbo_*
 * - 用户相关: user_*
 * - 管理相关: admin_*
 * - API相关: api_*
 * 
 * 参数验证：
 * - table_id: 台桌ID，必须是数字
 * - user_id: 用户ID，必须是数字
 * - game_number: 游戏局号，支持字母数字和连字符
 * - bet_id: 投注ID，必须是数字
 * 
 * RESTful API设计：
 * - GET: 获取资源
 * - POST: 创建资源
 * - PUT: 更新资源（完整更新）
 * - PATCH: 更新资源（部分更新）
 * - DELETE: 删除资源
 * 
 * 版本控制：
 * - 当前版本: v2.1 - 精简版
 * - API版本通过Header传递: X-API-Version
 * - 专注于骰宝游戏系统
 */