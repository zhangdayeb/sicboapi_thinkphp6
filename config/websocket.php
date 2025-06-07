<?php
/**
 * 骰宝WebSocket配置 - 修正版
 * 确保配置与Worker类匹配
 */

return [
    // ========================================
    // 服务器基础配置
    // ========================================
    'server' => [
        'host' => '0.0.0.0',
        'port' => 2009,                        // 确保端口是2009
        'worker_count' => 1,                   // 强制单进程
        'max_connections' => 1000,
        'name' => 'SicboWebSocket',
    ],

    // ========================================
    // Redis连接配置
    // ========================================
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', ''),
        'database' => env('REDIS_DATABASE', 0),
        'timeout' => 5,
        'prefix' => 'sicbo:',
        'cache_expire' => 5,                   // 内存缓存过期时间
    ],

    // ========================================
    // 游戏定时器配置
    // ========================================
    'game' => [
        'status_check_interval' => 1,          // 游戏状态检查间隔(秒)
        'countdown_strategy' => [
            'normal_intervals' => [30, 20, 10], // 正常间隔推送
            'final_countdown' => [5, 4, 3, 2, 1, 0] // 最后倒计时
        ],
    ],

    // ========================================
    // 连接管理配置
    // ========================================
    'connection' => [
        'heartbeat_interval' => 30,            // 心跳间隔(秒)
        'cleanup_interval' => 30,              // 清理间隔(秒)
        'auth_timeout' => 60,                  // 认证超时(秒)
        'ping_timeout' => 300,                 // Ping超时(秒)
        'max_table_connections' => 100,        // 单台桌最大连接数
    ],

    // ========================================
    // 消息处理配置
    // ========================================
    'message' => [
        'max_length' => 8192,                  // 最大消息长度(字节)
        'rate_limit' => 60,                    // 频率限制(每分钟)
        'auth_secret' => 'sicbo_websocket_secret_2024',
    ],

    // ========================================
    // 调试和监控配置
    // ========================================
    'debug' => [
        'log_messages' => env('WEBSOCKET_DEBUG', false),
        'enable_stats' => env('WEBSOCKET_STATS', true),
        'stats_interval' => 60,                // 统计输出间隔(秒)
    ],
];