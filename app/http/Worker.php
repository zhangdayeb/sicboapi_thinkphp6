<?php
// ========================================
// app/http/Worker.php - WebSocket服务入口
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
 * ThinkPHP6 + Workerman WebSocket Worker
 * 适配PHP 7.3版本的骰宝游戏WebSocket服务
 */
class Worker
{
    /**
     * WebSocket配置
     * @var array
     */
    private static $config = [];

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
     * Worker 启动时触发
     * @param \Workerman\Worker $worker
     */
    public function onWorkerStart($worker)
    {
        self::$startTime = time();
        self::$config = Config::get('websocket', []);
        
        $this->displayStartupInfo($worker);
        
        try {
            // 初始化各个管理器
            $this->initializeManagers();
            
            // 初始化Redis连接
            $this->initializeRedis();
            
            // 启动定时器
            $this->startTimers();
            
            // 记录启动日志
            Log::info('骰宝WebSocket Worker启动成功', [
                'worker_id' => $worker->id,
                'pid' => getmypid(),
                'listen' => $worker->getSocketName(),
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
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
        
        echo "[" . date('Y-m-d H:i:s') . "] 新连接: {$connectionId} from {$remoteIp}\n";
        
        try {
            // 检查安全配置
            if (!$this->checkConnectionSecurity($connection)) {
                $connection->close();
                return;
            }
            
            // 添加连接到管理器
            ConnectionManager::addConnection($connection);
            
            // 设置认证超时
            $this->setAuthTimeout($connection);
            
        } catch (\Exception $e) {
            echo "[ERROR] 处理新连接异常: " . $e->getMessage() . "\n";
            Log::error('处理新连接异常', [
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
        $connectionId = spl_object_hash($connection);
        
        try {
            // 记录调试信息
            if (self::$config['debug']['log_messages'] ?? false) {
                echo "[" . date('Y-m-d H:i:s') . "] 收到消息: {$connectionId} -> " . substr($data, 0, 100) . "\n";
            }
            
            // 更新连接活动时间
            ConnectionManager::updatePing($connectionId);
            
            // 交给消息处理器处理
            MessageHandler::handle($connection, $data);
            
        } catch (\Exception $e) {
            echo "[ERROR] 消息处理异常: " . $e->getMessage() . "\n";
            Log::error('消息处理异常', [
                'connection_id' => $connectionId,
                'data' => substr($data, 0, 500),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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
        echo "[" . date('Y-m-d H:i:s') . "] 连接关闭: {$connectionId}\n";
        
        try {
            // 从管理器中移除连接
            ConnectionManager::removeConnection($connection);
            
        } catch (\Exception $e) {
            echo "[ERROR] 处理连接关闭异常: " . $e->getMessage() . "\n";
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
        echo "[ERROR] 连接错误: {$connectionId}, Code: {$code}, Message: {$msg}\n";
        
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
        echo "=== 骰宝 WebSocket 服务停止 ===\n";
        echo "Worker ID: " . $worker->id . "\n";
        echo "停止时间: " . date('Y-m-d H:i:s') . "\n";
        echo "运行时长: " . $this->formatUptime(time() - self::$startTime) . "\n";
        echo "===============================\n";
        
        // 清理定时器
        foreach (self::$timers as $timerId) {
            Timer::del($timerId);
        }
        
        Log::info('骰宝WebSocket Worker停止', [
            'worker_id' => $worker->id,
            'uptime' => time() - self::$startTime
        ]);
    }

    /**
     * 显示启动信息
     * @param \Workerman\Worker $worker
     */
    private function displayStartupInfo($worker)
    {
        echo "=== 骰宝 WebSocket 服务启动 ===\n";
        echo "Worker ID: " . $worker->id . "\n";
        echo "PID: " . getmypid() . "\n";
        echo "监听地址: " . $worker->getSocketName() . "\n";
        echo "启动时间: " . date('Y-m-d H:i:s') . "\n";
        echo "PHP版本: " . PHP_VERSION . "\n";
        echo "ThinkPHP版本: 6.x\n";
        echo "Workerman版本: " . \Workerman\Worker::VERSION . "\n";
        echo "内存限制: " . ini_get('memory_limit') . "\n";
        echo "===============================\n";
    }

    /**
     * 初始化各个管理器
     */
    private function initializeManagers()
    {
        // 初始化连接管理器
        ConnectionManager::init();
        
        // 初始化Redis游戏管理器
        RedisGameManager::init(self::$config['redis'] ?? []);
        
        echo "管理器初始化完成\n";
    }

    /**
     * 初始化Redis连接
     */
    private function initializeRedis()
    {
        try {
            $redisConfig = self::$config['redis'] ?? [];
            
            // 测试Redis连接
            RedisGameManager::testConnection();
            
            echo "Redis连接初始化完成\n";
            
        } catch (\Exception $e) {
            throw new \Exception("Redis初始化失败: " . $e->getMessage());
        }
    }

    /**
     * 启动定时器
     */
    private function startTimers()
    {
        $gameConfig = self::$config['game'] ?? [];
        $connectionConfig = self::$config['connection'] ?? [];
        
        // 1. 游戏状态检查定时器（每秒执行）
        $gameTimerId = Timer::add(
            $gameConfig['status_check_interval'] ?? 1,
            [GameTimer::class, 'checkGameStatus']
        );
        self::$timers['game_timer'] = $gameTimerId;
        
        // 2. 连接清理定时器（每30秒执行）
        $cleanupTimerId = Timer::add(
            $connectionConfig['cleanup_interval'] ?? 30,
            [ConnectionManager::class, 'cleanup']
        );
        self::$timers['cleanup_timer'] = $cleanupTimerId;
        
        // 3. 心跳发送定时器（每25秒执行）
        $heartbeatTimerId = Timer::add(
            ($connectionConfig['heartbeat_interval'] ?? 30) - 5,
            [ConnectionManager::class, 'sendHeartbeat']
        );
        self::$timers['heartbeat_timer'] = $heartbeatTimerId;
        
        // 4. 状态统计定时器（每60秒执行）
        if (self::$config['monitor']['enable_stats'] ?? false) {
            $statsTimerId = Timer::add(
                self::$config['monitor']['stats_interval'] ?? 60,
                [$this, 'logStats']
            );
            self::$timers['stats_timer'] = $statsTimerId;
        }
        
        echo "定时器启动完成 (" . count(self::$timers) . "个)\n";
    }

    /**
     * 检查连接安全性
     * @param TcpConnection $connection
     * @return bool
     */
    private function checkConnectionSecurity(TcpConnection $connection)
    {
        $securityConfig = self::$config['security'] ?? [];
        
        // 检查Origin（如果启用）
        if ($securityConfig['origin_check'] ?? false) {
            $origin = $connection->headers['origin'] ?? '';
            $allowedOrigins = $securityConfig['allowed_origins'] ?? [];
            
            if (!empty($allowedOrigins) && !in_array($origin, $allowedOrigins)) {
                echo "拒绝连接：Origin不在白名单 - " . $origin . "\n";
                return false;
            }
        }
        
        // 检查频率限制（如果启用）
        if ($securityConfig['rate_limit']['enable'] ?? false) {
            // 这里可以实现简单的IP频率限制
            // 暂时返回true，实际项目中可以加入Redis计数器
        }
        
        return true;
    }

    /**
     * 设置认证超时
     * @param TcpConnection $connection
     */
    private function setAuthTimeout(TcpConnection $connection)
    {
        $authTimeout = self::$config['auth']['auth_timeout'] ?? 10;
        
        Timer::add($authTimeout, function() use ($connection) {
            $connectionId = spl_object_hash($connection);
            $connectionData = ConnectionManager::getConnection($connectionId);
            
            // 如果连接还存在且未认证，则关闭连接
            if ($connectionData && !$connectionData['auth_status']) {
                echo "连接认证超时，关闭连接: {$connectionId}\n";
                MessageHandler::sendError($connection, '认证超时');
                $connection->close();
            }
        }, [], false);
    }

    /**
     * 记录统计信息
     */
    public function logStats()
    {
        try {
            $stats = ConnectionManager::getOnlineStats();
            $memoryUsage = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);
            
            echo "[统计] 连接数: {$stats['total_connections']}, " .
                 "认证用户: {$stats['authenticated_users']}, " .
                 "活跃台桌: {$stats['active_tables']}, " .
                 "内存: " . $this->formatBytes($memoryUsage) . "\n";
            
            Log::info('WebSocket服务统计', [
                'connections' => $stats['total_connections'],
                'users' => $stats['authenticated_users'],
                'tables' => $stats['active_tables'],
                'memory_usage' => $memoryUsage,
                'memory_peak' => $memoryPeak,
                'uptime' => time() - self::$startTime
            ]);
            
        } catch (\Exception $e) {
            echo "[ERROR] 统计记录失败: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 处理启动错误
     * @param \Exception $e
     */
    private function handleStartupError(\Exception $e)
    {
        echo "[FATAL ERROR] Worker启动失败: " . $e->getMessage() . "\n";
        echo "文件: " . $e->getFile() . "\n";
        echo "行号: " . $e->getLine() . "\n";
        
        Log::error('WebSocket Worker启动失败', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // 退出进程
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
     * 格式化运行时间
     * @param int $seconds
     * @return string
     */
    private function formatUptime($seconds)
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        return "{$days}天 {$hours}小时 {$minutes}分钟 {$secs}秒";
    }
}