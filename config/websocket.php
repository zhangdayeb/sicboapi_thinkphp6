<?php

// ========================================
// config/websocket.php - WebSocket配置文件
// ========================================

return [
    // WebSocket服务器配置
    'server' => [
        'host' => '0.0.0.0',
        'port' => 2009,
        'protocol' => 'websocket',
        'ssl' => false,                    // 是否启用SSL
        'ssl_cert' => '',                  // SSL证书路径
        'ssl_key' => '',                   // SSL私钥路径
    ],

    // Worker进程配置
    'worker' => [
        'name' => 'SicboWebSocket',        // Worker名称
        'count' => 1,                      // 进程数量（单进程）
        'user' => '',                      // 运行用户
        'group' => '',                     // 运行用户组
        'reloadable' => true,              // 是否可重载
        'reusePort' => false,              // 是否重用端口
        'transport' => 'tcp',              // 传输协议
    ],

    // Redis配置
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'database' => 0,
        'timeout' => 5,
        'prefix' => 'sicbo:',              // Redis键前缀
    ],

    // 游戏配置
    'game' => [
        'default_betting_time' => 30,      // 默认投注时间(秒)
        'min_betting_time' => 10,          // 最小投注时间
        'max_betting_time' => 120,         // 最大投注时间
        
        // 倒计时推送策略
        'countdown_strategy' => [
            'normal_intervals' => [30, 20, 10],  // 正常间隔推送(秒)
            'final_countdown' => [5, 4, 3, 2, 1, 0],  // 最后倒计时(秒)
        ],
        
        // 游戏状态检查间隔
        'status_check_interval' => 1,      // 每秒检查一次
        
        // 游戏数据TTL
        'game_status_ttl' => 3600,         // 游戏状态缓存1小时
        'game_result_ttl' => 86400,        // 游戏结果缓存24小时  
        'win_info_ttl' => 3600,            // 中奖信息缓存1小时
    ],

    // 连接管理配置
    'connection' => [
        'heartbeat_interval' => 30,        // 心跳间隔(秒)
        'connection_timeout' => 120,       // 连接超时(秒)
        'max_connections_per_table' => 100, // 每台桌最大连接数
        'auth_timeout' => 10,              // 认证超时(秒)
        'cleanup_interval' => 30,          // 连接清理间隔(秒)
    ],

    // 认证配置
    'auth' => [
        'token_validation' => true,        // 是否验证token
        'allow_anonymous' => false,        // 是否允许匿名连接
        'token_cache_ttl' => 7200,         // token缓存时间(秒)
    ],

    // 推送配置
    'notification' => [
        'retry_count' => 3,                // 推送失败重试次数
        'retry_delay' => 1000,             // 重试延迟(毫秒)
        'batch_size' => 100,               // 批量推送大小
        'enable_compression' => false,     // 是否启用消息压缩
    ],

    // 日志配置
    'log' => [
        'level' => 'info',                 // 日志级别: debug|info|warning|error
        'max_files' => 30,                 // 最大日志文件数
        'file_prefix' => 'websocket_',     // 日志文件前缀
    ],

    // 监控配置
    'monitor' => [
        'enable_stats' => true,            // 是否启用统计
        'stats_interval' => 60,            // 统计间隔(秒)
        'performance_log' => false,        // 是否记录性能日志
    ],

    // 安全配置
    'security' => [
        'origin_check' => false,           // 是否检查Origin
        'allowed_origins' => [],           // 允许的Origin列表
        'rate_limit' => [
            'enable' => true,              // 是否启用频率限制
            'max_requests' => 100,         // 每分钟最大请求数
            'ban_duration' => 300,         // 封禁时长(秒)
        ],
    ],

    // 调试配置
    'debug' => [
        'enable' => true,                  // 是否启用调试模式
        'log_messages' => false,           // 是否记录所有消息
        'show_memory_usage' => true,       // 是否显示内存使用
        'show_connection_info' => true,    // 是否显示连接信息
    ],
];

