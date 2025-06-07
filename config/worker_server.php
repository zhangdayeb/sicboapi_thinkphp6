<?php
// ========================================
// config/worker_server.php - Worker Server配置
// 用于 php think worker:server 命令
// ========================================

return [
    // 监听协议和地址
    'protocol'       => 'websocket',           // 协议类型
    'host'           => '0.0.0.0',             // 监听地址
    'port'           => 2009,                  // 监听端口 - 强制2009
    'socket'         => 'websocket://0.0.0.0:2009', // 完整socket地址
    'context'        => [],                    // socket上下文选项

    // Worker配置
    'worker_class'   => '\app\http\Worker',    // 自定义Worker类
    'name'           => 'SicboWebSocket',      // Worker名称
    'count'          => 1,                     // 进程数 - 单进程
    'daemonize'      => false,                 // 是否守护进程模式
    'pidFile'        => '',                    // PID文件路径

    // Workerman全局配置
    'stdoutFile'     => '',                    // 标准输出重定向文件
    'logFile'        => '',                    // 日志文件路径
    'user'           => '',                    // 运行用户
    'group'          => '',                    // 运行用户组

    // 事件回调配置
    'onWorkerStart'  => null,                  // Worker启动回调
    'onWorkerReload' => null,                  // Worker重载回调
    'onConnect'      => null,                  // 连接建立回调
    'onMessage'      => null,                  // 消息接收回调
    'onClose'        => null,                  // 连接关闭回调
    'onError'        => null,                  // 错误回调
];