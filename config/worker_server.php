<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// +----------------------------------------------------------------------
// | Workerman设置 仅对 php think worker:server 指令有效
// +----------------------------------------------------------------------
return [
    // 扩展自身需要的配置
    'protocol'       => 'websocket', // 协议 支持 tcp udp unix http websocket text
    'host'           => '0.0.0.0', // 监听地址
    'port'           => 2009, // 监听端口（修改为2009）
    'socket'         => '', // 完整监听地址
    'context'        => [], // socket 上下文选项
    'worker_class'   => '\app\http\Worker', // 自定义Workerman服务类名

    // 支持workerman的所有配置参数
    'name'           => 'SicboWebSocket', // Worker名称
    'count'          => 1, // 进程数（单进程）
    'daemonize'      => false, // 是否守护进程模式
    'pidFile'        => '', // PID文件路径

    // 支持事件回调
    // onWorkerStart
    'onWorkerStart'  => function ($worker) {
        echo "骰宝WebSocket Worker进程启动\n";
    },
    
    // onWorkerReload
    'onWorkerReload' => function ($worker) {
        echo "骰宝WebSocket Worker进程重载\n";
    },
    
    // onConnect - 由Worker类处理
    'onConnect'      => null,
    
    // onMessage - 由Worker类处理  
    'onMessage'      => null,
    
    // onClose - 由Worker类处理
    'onClose'        => null,
    
    // onError - 由Worker类处理
    'onError'        => null,
];