<?php
use think\facade\Route;

/**
 * ========================================
 * 骰宝游戏系统路由配置 - 精简版
 * 只包含实际存在的控制器和方法
 * ========================================
 */

// ========================================
// 系统基础路由
// ========================================

// 简单的首页路由（如果Index控制器不存在，使用闭包函数）
Route::get('/', function() {
    return json([
        'system' => 'Sicbo Game System',
        'version' => '3.0.1',
        'status' => 'running',
        'timestamp' => time(),
        'message' => '骰宝游戏系统运行正常'
    ]);
})->name('homepage');

// 健康检查接口
Route::get('health', function() {
    try {
        // 测试数据库连接
        $dbStatus = 'connected';
        try {
            \think\facade\Db::query('SELECT 1');
        } catch (\Exception $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }
        
        // 测试缓存
        $cacheStatus = 'connected';
        try {
            \think\facade\Cache::set('health_test', time(), 60);
            \think\facade\Cache::get('health_test');
        } catch (\Exception $e) {
            $cacheStatus = 'error: ' . $e->getMessage();
        }
        
        return json([
            'status' => 'ok',
            'timestamp' => time(),
            'version' => '3.0.1',
            'services' => [
                'sicbo' => 'active',
                'database' => $dbStatus,
                'cache' => $cacheStatus
            ],
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB'
        ]);
    } catch (\Exception $e) {
        return json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'timestamp' => time()
        ], 500);
    }
})->name('health_check');

// 系统信息接口
Route::get('info', function() {
    return json([
        'system' => 'Sicbo Game System',
        'version' => '3.0.1',
        'api_version' => '2.0',
        'timestamp' => time(),
        'datetime' => date('Y-m-d H:i:s'),
        'timezone' => 'Asia/Shanghai',
        'available_modules' => [
            'sicbo_game' => 'SicboGameController',
            'sicbo_bet' => 'SicboBetController'
        ],
        'php_version' => PHP_VERSION
    ]);
})->name('system_info');

// ========================================
// 骰宝游戏核心路由组
// ========================================

/**
 * 骰宝游戏主控制器路由
 * 对应: app\controller\sicbo\SicboGameController
 * 实际存在的方法：getTableInfo, getGameHistory, getStatistics, startNewGame, 
 *              stopBetting, announceResult, getCurrentBetStats, getOddsInfo
 */
Route::group('sicbo/game', function () {
    
    // ===== 信息查询类路由 =====
    
    // 获取台桌游戏信息
    Route::get('table-info', 'sicbo.SicboGameController/getTableInfo')
        ->name('sicbo_table_info');
    
    // 获取游戏历史记录  
    Route::get('history', 'sicbo.SicboGameController/getGameHistory')
        ->name('sicbo_game_history');
    
    // 获取游戏统计数据
    Route::get('statistics', 'sicbo.SicboGameController/getStatistics')
        ->name('sicbo_game_statistics');
   
    
    // ===== 游戏控制类路由 =====
    
    // 开始新游戏局
    Route::post('start', 'sicbo.SicboGameController/startNewGame')
        ->name('sicbo_start_game');
        
});

// ========================================
// 骰宝投注路由组
// ========================================

/**
 * 骰宝投注控制器路由
 * 对应: app\controller\sicbo\SicboBetController  
 * 实际存在的方法：placeBet, modifyBet, cancelBet, getCurrentBets, 
 *              getBetHistory, getBetDetail, getUserBalance, getBetLimits, validateBet
 */
Route::group('sicbo/bet', function () {
    
    // ===== 投注操作类路由 =====
    
    // 提交用户投注
    Route::post('place', 'sicbo.SicboBetController/placeBet')
        ->name('sicbo_place_bet');    
    
    // ===== 投注查询类路由 =====
    
    // 获取用户当前投注
    Route::get('current', 'sicbo.SicboBetController/getCurrentBets')
        ->name('sicbo_current_bets');
    
    // 获取用户投注历史
    Route::get('history', 'sicbo.SicboBetController/getBetHistory')
        ->name('sicbo_bet_history');
    
    // 获取投注详情（带参数路由）
    Route::get('detail/:bet_id', 'sicbo.SicboBetController/getBetDetail')
        ->pattern(['bet_id' => '\d+'])
        ->name('sicbo_bet_detail');
    
    // ===== 用户信息类路由 =====
    
    // 获取用户余额信息
    Route::get('balance', 'sicbo.SicboBetController/getUserBalance')
        ->name('sicbo_user_balance');
    
    // 获取投注限额信息
    Route::get('limits', 'sicbo.SicboBetController/getBetLimits')
        ->name('sicbo_bet_limits');
});


// ========================================
// 错误处理
// ========================================

// 404 错误处理
Route::miss(function() {
    return json([
        'code' => 404,
        'message' => 'API接口不存在',
        'available_endpoints' => [
            'GET /' => '系统首页',
            'GET /health' => '健康检查', 
            'GET /info' => '系统信息',
            'GET /sicbo/game/*' => '游戏相关接口',
            'POST|PUT|DELETE /sicbo/bet/*' => '投注相关接口'
        ],
        'timestamp' => time()
    ], 404);
});

/**
 * ========================================
 * 路由说明文档
 * ========================================
 * 
 * 本路由文件仅包含实际存在的控制器和方法：
 * 
 * 1. SicboGameController (8个方法):
 *    - getTableInfo: 获取台桌信息
 *    - getGameHistory: 获取游戏历史  
 *    - getStatistics: 获取统计数据
 *    - getCurrentBetStats: 获取投注统计
 *    - getOddsInfo: 获取赔率信息
 *    - startNewGame: 开始新游戏
 *    - stopBetting: 停止投注
 *    - announceResult: 公布结果
 * 
 * 2. SicboBetController (9个方法):
 *    - placeBet: 提交投注
 *    - modifyBet: 修改投注
 *    - cancelBet: 取消投注  
 *    - getCurrentBets: 获取当前投注
 *    - getBetHistory: 获取投注历史
 *    - getBetDetail: 获取投注详情
 *    - getUserBalance: 获取用户余额
 *    - getBetLimits: 获取投注限额
 *    - validateBet: 验证投注
 * 
 * 3. 系统路由:
 *    - / : 系统首页
 *    - /health : 健康检查
 *    - /info : 系统信息
 *    - /test/* : 测试路由（仅调试模式）
 * 
 * 注意：已删除不存在的控制器路由：
 *    - SicboAdminController
 *    - SicboApiController  
 *    - Index控制器
 *    - Debug控制器
 * 
 * ========================================
 */