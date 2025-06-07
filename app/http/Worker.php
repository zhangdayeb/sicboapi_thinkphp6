<?php

namespace app\http;

use Workerman\Connection\TcpConnection;

/**
 * 简单的 ThinkPHP Workerman Worker 类
 */
class Worker
{
    /**
     * Worker 启动时触发
     */
    public function onWorkerStart($worker)
    {
        echo "ThinkPHP Workerman Worker 启动成功\n";
        echo "Worker ID: " . $worker->id . "\n";
        echo "PID: " . posix_getpid() . "\n";
        echo "监听地址: " . $worker->getSocketName() . "\n";
        echo "启动时间: " . date('Y-m-d H:i:s') . "\n";
    }

    /**
     * 当有新连接时触发
     */
    public function onConnect(TcpConnection $connection)
    {
        echo "新连接: " . $connection->getRemoteIp() . "\n";
        
        // 发送欢迎消息
        $connection->send(json_encode([
            'type' => 'welcome',
            'message' => '欢迎连接到 ThinkPHP Workerman 服务！',
            'time' => date('Y-m-d H:i:s')
        ]));
    }

    /**
     * 当收到消息时触发
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        echo "收到消息: " . $data . "\n";
        
        // 简单回显
        $response = [
            'type' => 'response',
            'original_message' => $data,
            'server_time' => date('Y-m-d H:i:s'),
            'message' => '服务器已收到您的消息'
        ];
        
        $connection->send(json_encode($response));
    }

    /**
     * 当连接关闭时触发
     */
    public function onClose(TcpConnection $connection)
    {
        echo "连接关闭: " . $connection->getRemoteIp() . "\n";
    }

    /**
     * 当连接出错时触发
     */
    public function onError(TcpConnection $connection, $code, $msg)
    {
        echo "连接错误: " . $connection->getRemoteIp() . " - $code: $msg\n";
    }
}