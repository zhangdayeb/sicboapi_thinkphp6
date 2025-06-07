<?php

namespace app\http;

use Workerman\Connection\TcpConnection;
use app\websocket\ConnectionManager;
use app\websocket\MessageHandler;
use think\facade\Log;

/**
 * ThinkPHP6 WebSocket Worker 主入口
 * 监听端口：2009，单进程
 */
class Worker
{
    /**
     * Worker 启动时触发
     * @param \Workerman\Worker $worker
     */
    public function onWorkerStart($worker)
    {
        echo "=== 骰宝 WebSocket 服务启动 ===\n";
        echo "Worker ID: " . $worker->id . "\n";
        echo "PID: " . posix_getpid() . "\n";
        echo "监听地址: " . $worker->getSocketName() . "\n";
        echo "启动时间: " . date('Y-m-d H:i:s') . "\n";
        echo "PHP版本: " . PHP_VERSION . "\n";
        echo "===============================\n";

        try {
            // 初始化连接管理器
            ConnectionManager::init();
            
            // 设置定时器 - 每30秒清理无效连接
            \Workerman\Lib\Timer::add(30, function() {
                ConnectionManager::cleanup();
            });

            // 设置定时器 - 每25秒发送心跳
            \Workerman\Lib\Timer::add(25, function() {
                ConnectionManager::sendHeartbeat();
            });

            Log::info('骰宝WebSocket Worker启动成功', [
                'worker_id' => $worker->id,
                'pid' => posix_getpid(),
                'listen' => $worker->getSocketName()
            ]);
            
        } catch (\Exception $e) {
            echo "Worker启动异常: " . $e->getMessage() . "\n";
            Log::error('Worker启动异常', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 新连接时触发
     * @param TcpConnection $connection
     */
    public function onConnect(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        echo "新连接: {$connectionId} from " . $connection->getRemoteIp() . "\n";
        
        try {
            // 添加连接到管理器
            ConnectionManager::addConnection($connection);
            
        } catch (\Exception $e) {
            echo "处理新连接异常: " . $e->getMessage() . "\n";
            Log::error('处理新连接异常', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 收到消息时触发
     * @param TcpConnection $connection
     * @param string $data
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        $connectionId = spl_object_hash($connection);
        
        try {
            // 交给消息处理器处理
            MessageHandler::handle($connection, $data);
            
        } catch (\Exception $e) {
            echo "消息处理异常: " . $e->getMessage() . "\n";
            Log::error('消息处理异常', [
                'connection_id' => $connectionId,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            
            // 发送错误响应
            MessageHandler::sendError($connection, '消息处理失败');
        }
    }

    /**
     * 连接关闭时触发
     * @param TcpConnection $connection
     */
    public function onClose(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        echo "连接关闭: {$connectionId}\n";
        
        try {
            // 从管理器中移除连接
            ConnectionManager::removeConnection($connection);
            
        } catch (\Exception $e) {
            echo "处理连接关闭异常: " . $e->getMessage() . "\n";
            Log::error('处理连接关闭异常', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 连接错误时触发
     * @param TcpConnection $connection
     * @param int $code
     * @param string $msg
     */
    public function onError(TcpConnection $connection, $code, $msg)
    {
        $connectionId = spl_object_hash($connection);
        echo "连接错误: {$connectionId}, Code: {$code}, Message: {$msg}\n";
        
        Log::error('WebSocket连接错误', [
            'connection_id' => $connectionId,
            'code' => $code,
            'message' => $msg
        ]);
    }
}