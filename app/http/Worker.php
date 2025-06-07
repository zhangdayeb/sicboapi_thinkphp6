<?php
// ========================================
// app/http/Worker.php - 优化版
// ========================================
namespace app\http;

use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use app\websocket\ConnectionManager;
use app\websocket\MessageHandler;
use app\websocket\GameTimer;
use app\websocket\RedisGameManager;
use think\facade\Log;
use think\facade\Config;

/**
 * 骰宝WebSocket Worker - 优化版
 * 简化代码结构，保留核心功能
 */
class Worker
{
    /**
     * 定时器ID列表
     * @var array
     */
    private static $timers = [];

    /**
     * 服务启动时间
     * @var int
     */
    private static $startTime = 0;

    /**
     * WebSocket配置
     * @var array
     */
    private static $config = [];

    /**
     * Worker 启动时触发
     * @param \Workerman\Worker $worker
     */
    public function onWorkerStart($worker)
    {
        self::$startTime = time();
        self::$config = Config::get('websocket', []);
        
        echo "=== 骰宝WebSocket服务启动 ===\n";
        echo "Worker ID: {$worker->id}, PID: " . getmypid() . "\n";
        echo "监听地址: {$worker->getSocketName()}\n";
        echo "启动时间: " . date('Y-m-d H:i:s') . "\n";
        echo "==============================\n";
        
        try {
            $this->initializeServices();
            $this->startTimers();
            
            Log::info('骰宝WebSocket Worker启动成功', [
                'worker_id' => $worker->id,
                'pid' => getmypid(),
                'listen' => $worker->getSocketName()
            ]);
            
        } catch (\Exception $e) {
            $this->handleStartupError($e);
        }
    }

    /**
     * 新连接时触发
     * @param TcpConnection $connection
     */
    public function onConnect(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $remoteIp = $connection->getRemoteIp();
        
        try {
            // 基础安全检查
            if (!$this->validateConnection($connection)) {
                $connection->close();
                return;
            }
            
            // 添加到连接管理器
            ConnectionManager::addConnection($connection);
            
            // 设置认证超时
            $this->setAuthTimeout($connection);
            
            echo "[" . date('H:i:s') . "] 新连接: {$remoteIp}\n";
            
        } catch (\Exception $e) {
            echo "[ERROR] 连接处理失败: " . $e->getMessage() . "\n";
            Log::error('新连接处理异常', [
                'connection_id' => $connectionId,
                'remote_ip' => $remoteIp,
                'error' => $e->getMessage()
            ]);
            $connection->close();
        }
    }

    /**
     * 收到消息时触发
     * @param TcpConnection $connection
     * @param string $data
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        try {
            // 调试日志（可配置）
            if (self::$config['debug']['log_messages'] ?? false) {
                $preview = substr($data, 0, 100);
                echo "[" . date('H:i:s') . "] 消息: {$preview}...\n";
            }
            
            // 更新连接活动时间
            ConnectionManager::updatePing(spl_object_hash($connection));
            
            // 交给消息处理器
            MessageHandler::handle($connection, $data);
            
        } catch (\Exception $e) {
            echo "[ERROR] 消息处理异常: " . $e->getMessage() . "\n";
            Log::error('消息处理异常', [
                'connection_id' => spl_object_hash($connection),
                'data_preview' => substr($data, 0, 200),
                'error' => $e->getMessage()
            ]);
            
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
        
        try {
            ConnectionManager::removeConnection($connection);
            echo "[" . date('H:i:s') . "] 连接关闭: " . $connection->getRemoteIp() . "\n";
            
        } catch (\Exception $e) {
            echo "[ERROR] 连接关闭处理异常: " . $e->getMessage() . "\n";
            Log::error('连接关闭异常', [
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
        
        echo "[ERROR] 连接错误: Code {$code}, Message: {$msg}\n";
        
        Log::error('WebSocket连接错误', [
            'connection_id' => $connectionId,
            'code' => $code,
            'message' => $msg,
            'remote_ip' => $connection->getRemoteIp()
        ]);
        
        // 清理连接
        try {
            ConnectionManager::removeConnection($connection);
        } catch (\Exception $e) {
            // 忽略清理异常
        }
    }

    /**
     * Worker 停止时触发
     * @param \Workerman\Worker $worker
     */
    public function onWorkerStop($worker)
    {
        $uptime = time() - self::$startTime;
        
        echo "=== 骰宝WebSocket服务停止 ===\n";
        echo "Worker ID: {$worker->id}\n";
        echo "运行时长: " . $this->formatDuration($uptime) . "\n";
        echo "停止时间: " . date('Y-m-d H:i:s') . "\n";
        echo "============================\n";
        
        // 清理定时器
        foreach (self::$timers as $timerId) {
            Timer::del($timerId);
        }
        
        // 关闭Redis连接
        RedisGameManager::close();
        
        Log::info('骰宝WebSocket Worker停止', [
            'worker_id' => $worker->id,
            'uptime' => $uptime
        ]);
    }

    // ========================================
    // 私有方法 - 简化版
    // ========================================

    /**
     * 初始化服务
     */
    private function initializeServices()
    {
        // 初始化连接管理器
        ConnectionManager::init();
        
        // 初始化Redis
        $redisConfig = self::$config['redis'] ?? [];
        RedisGameManager::init($redisConfig);
        
        // 测试Redis连接
        if (!RedisGameManager::testConnection()) {
            throw new \Exception('Redis连接测试失败');
        }
        
        echo "服务初始化完成\n";
    }

    /**
     * 启动定时器
     */
    private function startTimers()
    {
        $gameConfig = self::$config['game'] ?? [];
        $connectionConfig = self::$config['connection'] ?? [];
        
        // 游戏状态检查定时器
        self::$timers['game'] = Timer::add(
            $gameConfig['status_check_interval'] ?? 1,
            [GameTimer::class, 'checkGameStatus']
        );
        
        // 连接清理定时器
        self::$timers['cleanup'] = Timer::add(
            $connectionConfig['cleanup_interval'] ?? 30,
            [ConnectionManager::class, 'cleanup']
        );
        
        // 心跳定时器
        self::$timers['heartbeat'] = Timer::add(
            ($connectionConfig['heartbeat_interval'] ?? 30) - 5,
            [ConnectionManager::class, 'sendHeartbeat']
        );
        
        // 统计定时器（可选）
        if (self::$config['debug']['enable_stats'] ?? false) {
            self::$timers['stats'] = Timer::add(
                self::$config['debug']['stats_interval'] ?? 60,
                [$this, 'outputStats']
            );
        }
        
        echo "定时器启动完成 (" . count(self::$timers) . "个)\n";
    }

    /**
     * 验证连接（简化版安全检查）
     * @param TcpConnection $connection
     * @return bool
     */
    private function validateConnection(TcpConnection $connection)
    {
        // 简单的连接验证
        $maxConnections = self::$config['server']['max_connections'] ?? 1000;
        $currentConnections = ConnectionManager::getOnlineStats()['total_connections'] ?? 0;
        
        if ($currentConnections >= $maxConnections) {
            echo "拒绝连接：已达到最大连接数限制\n";
            return false;
        }
        
        return true;
    }

    /**
     * 设置认证超时
     * @param TcpConnection $connection
     */
    private function setAuthTimeout(TcpConnection $connection)
    {
        $authTimeout = self::$config['connection']['auth_timeout'] ?? 60;
        
        Timer::add($authTimeout, function() use ($connection) {
            $connectionId = spl_object_hash($connection);
            $connectionData = ConnectionManager::getConnection($connectionId);
            
            // 如果连接还存在且未认证，则关闭连接
            if ($connectionData && !$connectionData['auth_status']) {
                echo "连接认证超时: " . $connection->getRemoteIp() . "\n";
                MessageHandler::sendError($connection, '认证超时');
                $connection->close();
            }
        }, [], false);
    }

    /**
     * 输出统计信息
     */
    public function outputStats()
    {
        try {
            $stats = ConnectionManager::getOnlineStats();
            $memory = $this->formatBytes(memory_get_usage(true));
            
            echo "[统计] 连接数: {$stats['total_connections']}, " .
                 "认证用户: {$stats['authenticated_users']}, " .
                 "活跃台桌: {$stats['active_tables']}, " .
                 "内存: {$memory}\n";
            
        } catch (\Exception $e) {
            echo "[ERROR] 统计输出失败: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 处理启动错误
     * @param \Exception $e
     */
    private function handleStartupError(\Exception $e)
    {
        echo "[FATAL] Worker启动失败: " . $e->getMessage() . "\n";
        echo "文件: " . $e->getFile() . ":" . $e->getLine() . "\n";
        
        Log::error('WebSocket Worker启动失败', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        
        exit(1);
    }

    /**
     * 格式化字节数
     * @param int $bytes
     * @return string
     */
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 格式化时长
     * @param int $seconds
     * @return string
     */
    private function formatDuration($seconds)
    {
        if ($seconds < 60) {
            return "{$seconds}秒";
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return "{$minutes}分{$secs}秒";
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return "{$hours}小时{$minutes}分钟";
        }
    }
}