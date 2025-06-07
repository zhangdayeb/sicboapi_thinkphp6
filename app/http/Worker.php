<?php



namespace app\http;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use think\App;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Db;

/**
 * 骰宝游戏WebSocket Worker服务
 * 
 * 功能说明：
 * - 处理骰宝游戏的WebSocket连接
 * - 管理台桌实时通信
 * - 处理投注和游戏状态推送
 * - 支持多种消息类型和广播
 * 
 * 配置：
 * - 端口：2009
 * - 进程：1个（单进程）
 * - 协议：WebSocket
 */
class Worker
{
/**
 * @var Worker|null
 */
private static $worker = null;

/**
 * @var App|null  
 */
private static $app = null;

    /**
     * 连接管理
     * 格式：['connection_id' => ['user_id' => xx, 'table_id' => xx, 'connection' => xx]]
     */
    private static array $connections = [];

    /**
     * 台桌连接映射
     * 格式：['table_id' => ['connection_id1', 'connection_id2', ...]]
     */
    private static array $tableConnections = [];

    /**
     * 用户连接映射
     * 格式：['user_id' => ['connection_id1', 'connection_id2', ...]]
     */
    private static array $userConnections = [];

    /**
     * 消息类型常量
     */
    private const MSG_TYPE_PING = 'ping';
    private const MSG_TYPE_PONG = 'pong';
    private const MSG_TYPE_AUTH = 'auth';
    private const MSG_TYPE_JOIN_TABLE = 'join_table';
    private const MSG_TYPE_LEAVE_TABLE = 'leave_table';
    private const MSG_TYPE_GAME_STATUS = 'game_status';
    private const MSG_TYPE_BET_UPDATE = 'bet_update';
    private const MSG_TYPE_GAME_RESULT = 'game_result';
    private const MSG_TYPE_SETTLEMENT = 'settlement';
    private const MSG_TYPE_NOTIFICATION = 'notification';
    private const MSG_TYPE_ERROR = 'error';

    /**
     * 启动Worker服务
     */
    public static function start(): void
    {
        // 创建WebSocket Worker，监听2009端口，单进程
        self::$worker = new Worker('websocket://0.0.0.0:2009');
        
        // 设置进程数为1（单进程）
        self::$worker->count = 1;
        
        // 设置进程名称
        self::$worker->name = 'SicboGameWebSocket';

        // 初始化ThinkPHP应用
        self::initThinkPHP();

        // 设置回调函数
        self::$worker->onWorkerStart = [self::class, 'onWorkerStart'];
        self::$worker->onConnect = [self::class, 'onConnect'];
        self::$worker->onMessage = [self::class, 'onMessage'];
        self::$worker->onClose = [self::class, 'onClose'];
        self::$worker->onError = [self::class, 'onError'];

        Log::info('骰宝WebSocket Worker启动，监听端口2009，单进程模式');
    }

    /**
     * 初始化ThinkPHP应用
     */
    private static function initThinkPHP(): void
    {
        try {
            // 加载ThinkPHP
            require_once __DIR__ . '/../../vendor/autoload.php';
            
            self::$app = new App();
            self::$app->initialize();
            
            // 设置运行模式
            self::$app->debug(true);
            
            Log::info('ThinkPHP应用初始化成功');
        } catch (\Exception $e) {
            Log::error('ThinkPHP应用初始化失败: ' . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Worker启动回调
     */
    public static function onWorkerStart(Worker $worker): void
    {
        Log::info('骰宝WebSocket Worker进程启动', [
            'worker_id' => $worker->id,
            'pid' => posix_getpid(),
            'listen' => $worker->getSocketName()
        ]);

        // 设置定时器，定期清理无效连接
        \Workerman\Lib\Timer::add(30, function() {
            self::cleanupConnections();
        });

        // 设置定时器，定期推送心跳
        \Workerman\Lib\Timer::add(25, function() {
            self::sendHeartbeat();
        });

        // 设置定时器，同步在线统计
        \Workerman\Lib\Timer::add(60, function() {
            self::updateOnlineStats();
        });

        echo "骰宝WebSocket服务启动成功，监听端口：2009\n";
        echo "进程ID：" . posix_getpid() . "\n";
        echo "时间：" . date('Y-m-d H:i:s') . "\n";
    }

    /**
     * 新连接回调
     */
    public static function onConnect(TcpConnection $connection): void
    {
        $connectionId = spl_object_hash($connection);
        
        // 记录连接信息
        self::$connections[$connectionId] = [
            'user_id' => null,
            'table_id' => null,
            'connection' => $connection,
            'connect_time' => time(),
            'last_ping' => time(),
            'auth_status' => false
        ];

        Log::info('新WebSocket连接', [
            'connection_id' => $connectionId,
            'remote_ip' => $connection->getRemoteIp(),
            'remote_port' => $connection->getRemotePort()
        ]);

        // 发送欢迎消息
        self::sendToConnection($connection, [
            'type' => 'welcome',
            'message' => '欢迎连接骰宝游戏服务',
            'connection_id' => $connectionId,
            'server_time' => time()
        ]);
    }

    /**
     * 消息处理回调
     */
    public static function onMessage(TcpConnection $connection, string $data): void
    {
        $connectionId = spl_object_hash($connection);
        
        try {
            $message = json_decode($data, true);
            
            if (!$message || !isset($message['type'])) {
                self::sendError($connection, '消息格式错误');
                return;
            }

            $messageType = $message['type'];
            
            Log::debug('收到WebSocket消息', [
                'connection_id' => $connectionId,
                'type' => $messageType,
                'data' => $message
            ]);

            // 更新最后活动时间
            if (isset(self::$connections[$connectionId])) {
                self::$connections[$connectionId]['last_ping'] = time();
            }

            // 根据消息类型处理
            switch ($messageType) {
                case self::MSG_TYPE_PING:
                    self::handlePing($connection, $message);
                    break;

                case self::MSG_TYPE_AUTH:
                    self::handleAuth($connection, $message);
                    break;

                case self::MSG_TYPE_JOIN_TABLE:
                    self::handleJoinTable($connection, $message);
                    break;

                case self::MSG_TYPE_LEAVE_TABLE:
                    self::handleLeaveTable($connection, $message);
                    break;

                case self::MSG_TYPE_GAME_STATUS:
                    self::handleGameStatusRequest($connection, $message);
                    break;

                case self::MSG_TYPE_BET_UPDATE:
                    self::handleBetUpdate($connection, $message);
                    break;

                default:
                    self::sendError($connection, '未知的消息类型: ' . $messageType);
                    break;
            }

        } catch (\Exception $e) {
            Log::error('WebSocket消息处理异常', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            self::sendError($connection, '消息处理失败');
        }
    }

    /**
     * 连接关闭回调
     */
    public static function onClose(TcpConnection $connection): void
    {
        $connectionId = spl_object_hash($connection);
        
        Log::info('WebSocket连接关闭', [
            'connection_id' => $connectionId
        ]);

        self::removeConnection($connectionId);
    }

    /**
     * 错误回调
     */
    public static function onError(TcpConnection $connection, int $code, string $msg): void
    {
        $connectionId = spl_object_hash($connection);
        
        Log::error('WebSocket连接错误', [
            'connection_id' => $connectionId,
            'code' => $code,
            'message' => $msg
        ]);
    }

    /**
     * 处理心跳消息
     */
    private static function handlePing(TcpConnection $connection, array $message): void
    {
        self::sendToConnection($connection, [
            'type' => self::MSG_TYPE_PONG,
            'timestamp' => time()
        ]);
    }

    /**
     * 处理用户认证
     */
    private static function handleAuth(TcpConnection $connection, array $message): void
    {
        $connectionId = spl_object_hash($connection);
        
        try {
            $token = $message['token'] ?? '';
            
            if (empty($token)) {
                self::sendError($connection, '认证token不能为空');
                return;
            }

            // 验证token并获取用户信息
            $userInfo = self::validateToken($token);
            
            if (!$userInfo) {
                self::sendError($connection, '认证失败，无效的token');
                return;
            }

            $userId = $userInfo['user_id'];

            // 更新连接信息
            self::$connections[$connectionId]['user_id'] = $userId;
            self::$connections[$connectionId]['auth_status'] = true;

            // 添加到用户连接映射
            if (!isset(self::$userConnections[$userId])) {
                self::$userConnections[$userId] = [];
            }
            self::$userConnections[$userId][] = $connectionId;

            // 发送认证成功消息
            self::sendToConnection($connection, [
                'type' => 'auth_success',
                'user_id' => $userId,
                'user_info' => $userInfo,
                'message' => '认证成功'
            ]);

            Log::info('用户WebSocket认证成功', [
                'connection_id' => $connectionId,
                'user_id' => $userId
            ]);

        } catch (\Exception $e) {
            Log::error('WebSocket认证异常: ' . $e->getMessage());
            self::sendError($connection, '认证处理失败');
        }
    }

    /**
     * 处理加入台桌
     */
    private static function handleJoinTable(TcpConnection $connection, array $message): void
    {
        $connectionId = spl_object_hash($connection);
        
        // 检查是否已认证
        if (!self::$connections[$connectionId]['auth_status']) {
            self::sendError($connection, '请先进行身份认证');
            return;
        }

        $tableId = (int)($message['table_id'] ?? 0);
        
        if ($tableId <= 0) {
            self::sendError($connection, '无效的台桌ID');
            return;
        }

        try {
            // 验证台桌是否存在且可用
            $table = self::getTableInfo($tableId);
            
            if (!$table) {
                self::sendError($connection, '台桌不存在或已关闭');
                return;
            }

            // 离开之前的台桌（如果有）
            $oldTableId = self::$connections[$connectionId]['table_id'];
            if ($oldTableId) {
                self::leaveTableInternal($connectionId, $oldTableId);
            }

            // 加入新台桌
            self::$connections[$connectionId]['table_id'] = $tableId;

            // 添加到台桌连接映射
            if (!isset(self::$tableConnections[$tableId])) {
                self::$tableConnections[$tableId] = [];
            }
            self::$tableConnections[$tableId][] = $connectionId;

            // 获取台桌当前状态
            $gameStatus = self::getTableGameStatus($tableId);

            // 发送加入成功消息
            self::sendToConnection($connection, [
                'type' => 'join_table_success',
                'table_id' => $tableId,
                'table_info' => $table,
                'game_status' => $gameStatus,
                'message' => '成功加入台桌'
            ]);

            // 广播用户加入台桌消息（给台桌其他用户）
            self::broadcastToTable($tableId, [
                'type' => 'user_joined',
                'user_id' => self::$connections[$connectionId]['user_id'],
                'table_id' => $tableId
            ], [$connectionId]);

            Log::info('用户加入台桌', [
                'connection_id' => $connectionId,
                'user_id' => self::$connections[$connectionId]['user_id'],
                'table_id' => $tableId
            ]);

        } catch (\Exception $e) {
            Log::error('加入台桌异常: ' . $e->getMessage());
            self::sendError($connection, '加入台桌失败');
        }
    }

    /**
     * 处理离开台桌
     */
    private static function handleLeaveTable(TcpConnection $connection, array $message): void
    {
        $connectionId = spl_object_hash($connection);
        $tableId = self::$connections[$connectionId]['table_id'] ?? null;

        if (!$tableId) {
            self::sendError($connection, '您当前不在任何台桌中');
            return;
        }

        self::leaveTableInternal($connectionId, $tableId);

        self::sendToConnection($connection, [
            'type' => 'leave_table_success',
            'table_id' => $tableId,
            'message' => '成功离开台桌'
        ]);
    }

    /**
     * 处理游戏状态请求
     */
    private static function handleGameStatusRequest(TcpConnection $connection, array $message): void
    {
        $connectionId = spl_object_hash($connection);
        $tableId = self::$connections[$connectionId]['table_id'] ?? null;

        if (!$tableId) {
            self::sendError($connection, '请先加入台桌');
            return;
        }

        try {
            $gameStatus = self::getTableGameStatus($tableId);
            
            self::sendToConnection($connection, [
                'type' => 'game_status_response',
                'table_id' => $tableId,
                'game_status' => $gameStatus
            ]);

        } catch (\Exception $e) {
            Log::error('获取游戏状态异常: ' . $e->getMessage());
            self::sendError($connection, '获取游戏状态失败');
        }
    }

    /**
     * 处理投注更新
     */
    private static function handleBetUpdate(TcpConnection $connection, array $message): void
    {
        $connectionId = spl_object_hash($connection);
        $userId = self::$connections[$connectionId]['user_id'] ?? null;
        $tableId = self::$connections[$connectionId]['table_id'] ?? null;

        if (!$userId || !$tableId) {
            self::sendError($connection, '请先登录并加入台桌');
            return;
        }

        try {
            // 获取用户当前投注
            $currentBets = self::getUserCurrentBets($userId, $tableId);
            
            // 广播投注更新给台桌其他用户（不包含具体金额）
            self::broadcastToTable($tableId, [
                'type' => 'bet_update_notification',
                'table_id' => $tableId,
                'has_new_bet' => !empty($currentBets),
                'timestamp' => time()
            ], [$connectionId]);

        } catch (\Exception $e) {
            Log::error('投注更新处理异常: ' . $e->getMessage());
        }
    }

    /**
     * 内部离开台桌处理
     */
    private static function leaveTableInternal(string $connectionId, int $tableId): void
    {
        // 从台桌连接映射中移除
        if (isset(self::$tableConnections[$tableId])) {
            $key = array_search($connectionId, self::$tableConnections[$tableId]);
            if ($key !== false) {
                unset(self::$tableConnections[$tableId][$key]);
                self::$tableConnections[$tableId] = array_values(self::$tableConnections[$tableId]);
            }

            // 如果台桌没有连接了，删除台桌映射
            if (empty(self::$tableConnections[$tableId])) {
                unset(self::$tableConnections[$tableId]);
            }
        }

        // 更新连接信息
        self::$connections[$connectionId]['table_id'] = null;

        // 广播用户离开消息
        self::broadcastToTable($tableId, [
            'type' => 'user_left',
            'user_id' => self::$connections[$connectionId]['user_id'],
            'table_id' => $tableId
        ]);

        Log::info('用户离开台桌', [
            'connection_id' => $connectionId,
            'user_id' => self::$connections[$connectionId]['user_id'],
            'table_id' => $tableId
        ]);
    }

    /**
     * 移除连接
     */
    private static function removeConnection(string $connectionId): void
    {
        if (!isset(self::$connections[$connectionId])) {
            return;
        }

        $connection = self::$connections[$connectionId];
        $userId = $connection['user_id'];
        $tableId = $connection['table_id'];

        // 从台桌中移除
        if ($tableId) {
            self::leaveTableInternal($connectionId, $tableId);
        }

        // 从用户连接映射中移除
        if ($userId && isset(self::$userConnections[$userId])) {
            $key = array_search($connectionId, self::$userConnections[$userId]);
            if ($key !== false) {
                unset(self::$userConnections[$userId][$key]);
                self::$userConnections[$userId] = array_values(self::$userConnections[$userId]);
            }

            // 如果用户没有连接了，删除用户映射
            if (empty(self::$userConnections[$userId])) {
                unset(self::$userConnections[$userId]);
            }
        }

        // 移除连接记录
        unset(self::$connections[$connectionId]);
    }

    /**
     * 发送消息到指定连接
     */
    private static function sendToConnection(TcpConnection $connection, array $data): void
    {
        try {
            $message = json_encode($data, JSON_UNESCAPED_UNICODE);
            $connection->send($message);
        } catch (\Exception $e) {
            Log::error('发送WebSocket消息失败: ' . $e->getMessage());
        }
    }

    /**
     * 发送错误消息
     */
    private static function sendError(TcpConnection $connection, string $message): void
    {
        self::sendToConnection($connection, [
            'type' => self::MSG_TYPE_ERROR,
            'message' => $message,
            'timestamp' => time()
        ]);
    }

    /**
     * 广播消息到台桌
     */
    public static function broadcastToTable(int $tableId, array $data, array $excludeConnections = []): void
    {
        if (!isset(self::$tableConnections[$tableId])) {
            return;
        }

        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        foreach (self::$tableConnections[$tableId] as $connectionId) {
            if (in_array($connectionId, $excludeConnections)) {
                continue;
            }

            if (isset(self::$connections[$connectionId])) {
                try {
                    $connection = self::$connections[$connectionId]['connection'];
                    $connection->send($message);
                } catch (\Exception $e) {
                    Log::error('台桌广播发送失败: ' . $e->getMessage());
                }
            }
        }

        Log::debug('台桌广播消息', [
            'table_id' => $tableId,
            'message_type' => $data['type'] ?? 'unknown',
            'connection_count' => count(self::$tableConnections[$tableId]) - count($excludeConnections)
        ]);
    }

    /**
     * 发送消息到指定用户
     */
    public static function sendToUser(int $userId, array $data): void
    {
        if (!isset(self::$userConnections[$userId])) {
            return;
        }

        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        foreach (self::$userConnections[$userId] as $connectionId) {
            if (isset(self::$connections[$connectionId])) {
                try {
                    $connection = self::$connections[$connectionId]['connection'];
                    $connection->send($message);
                } catch (\Exception $e) {
                    Log::error('用户消息发送失败: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * 全平台广播
     */
    public static function broadcastAll(array $data): void
    {
        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        foreach (self::$connections as $connectionId => $connectionData) {
            try {
                $connection = $connectionData['connection'];
                $connection->send($message);
            } catch (\Exception $e) {
                Log::error('全平台广播发送失败: ' . $e->getMessage());
            }
        }

        Log::info('全平台广播消息', [
            'message_type' => $data['type'] ?? 'unknown',
            'connection_count' => count(self::$connections)
        ]);
    }

    /**
     * 清理无效连接
     */
    private static function cleanupConnections(): void
    {
        $now = time();
        $timeout = 120; // 2分钟超时
        $cleanedCount = 0;

        foreach (self::$connections as $connectionId => $connectionData) {
            if ($now - $connectionData['last_ping'] > $timeout) {
                $connection = $connectionData['connection'];
                $connection->close();
                self::removeConnection($connectionId);
                $cleanedCount++;
            }
        }

        if ($cleanedCount > 0) {
            Log::info('清理无效WebSocket连接', ['cleaned_count' => $cleanedCount]);
        }
    }

    /**
     * 发送心跳
     */
    private static function sendHeartbeat(): void
    {
        $heartbeatData = [
            'type' => 'heartbeat',
            'timestamp' => time(),
            'online_count' => count(self::$connections)
        ];

        foreach (self::$connections as $connectionData) {
            try {
                $connection = $connectionData['connection'];
                self::sendToConnection($connection, $heartbeatData);
            } catch (\Exception $e) {
                // 忽略发送失败的连接，会被清理程序处理
            }
        }
    }

    /**
     * 更新在线统计
     */
    private static function updateOnlineStats(): void
    {
        try {
            $stats = [
                'total_connections' => count(self::$connections),
                'authenticated_users' => count(self::$userConnections),
                'active_tables' => count(self::$tableConnections),
                'update_time' => time()
            ];

            Cache::set('websocket_online_stats', $stats, 300);

            // 统计各台桌在线人数
            $tableStats = [];
            foreach (self::$tableConnections as $tableId => $connections) {
                $tableStats[$tableId] = count($connections);
            }
            
            Cache::set('table_online_stats', $tableStats, 300);

        } catch (\Exception $e) {
            Log::error('更新在线统计失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证用户token
     */
    private static function validateToken(string $token): ?array
    {
        try {
            // 这里实现token验证逻辑
            // 可以是JWT验证、数据库查询等
            
            // 示例：从缓存或数据库验证token
            $userInfo = Cache::get('user_token_' . $token);
            
            if (!$userInfo) {
                // 从数据库查询
                $user = Db::table('users')
                    ->where('token', $token)
                    ->where('status', 1)
                    ->find();
                    
                if ($user) {
                    $userInfo = [
                        'user_id' => $user['id'],
                        'username' => $user['username'],
                        'nickname' => $user['nickname'] ?? '',
                        'avatar' => $user['avatar'] ?? '',
                        'level' => $user['level'] ?? 1
                    ];
                    
                    // 缓存用户信息
                    Cache::set('user_token_' . $token, $userInfo, 7200);
                }
            }
            
            return $userInfo;
            
        } catch (\Exception $e) {
            Log::error('Token验证异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取台桌信息
     */
    private static function getTableInfo(int $tableId): ?array
    {
        try {
            $table = Db::table('ntp_dianji_table')
                ->where('id', $tableId)
                ->where('status', 1)
                ->find();
                
            return $table;
            
        } catch (\Exception $e) {
            Log::error('获取台桌信息异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取台桌游戏状态
     */
    private static function getTableGameStatus(int $tableId): array
    {
        try {
            // 从缓存获取游戏状态
            $status = Cache::get("table_game_status_{$tableId}");
            
            if (!$status) {
                // 默认状态
                $status = [
                    'table_id' => $tableId,
                    'status' => 'waiting',
                    'countdown' => 0,
                    'current_game' => null,
                    'last_result' => null,
                    'update_time' => time()
                ];
            }
            
            return $status;
            
        } catch (\Exception $e) {
            Log::error('获取游戏状态异常: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取用户当前投注
     */
    private static function getUserCurrentBets(int $userId, int $tableId): array
    {
        try {
            // 获取当前游戏局号
            $gameStatus = self::getTableGameStatus($tableId);
            $gameNumber = $gameStatus['current_game'] ?? null;
            
            if (!$gameNumber) {
                return [];
            }
            
            $bets = Db::table('ntp_sicbo_bet_records')
                ->where('user_id', $userId)
                ->where('table_id', $tableId)
                ->where('game_number', $gameNumber)
                ->where('settle_status', 0)
                ->select();
                
            return $bets ? $bets->toArray() : [];
            
        } catch (\Exception $e) {
            Log::error('获取用户投注异常: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取在线统计
     */
    public static function getOnlineStats(): array
    {
        return [
            'total_connections' => count(self::$connections),
            'authenticated_users' => count(self::$userConnections),
            'active_tables' => count(self::$tableConnections),
            'table_details' => array_map('count', self::$tableConnections)
        ];
    }

    /**
     * 获取台桌连接数
     */
    public static function getTableConnectionCount(int $tableId): int
    {
        return count(self::$tableConnections[$tableId] ?? []);
    }

    /**
     * 检查用户是否在线
     */
    public static function isUserOnline(int $userId): bool
    {
        return isset(self::$userConnections[$userId]) && !empty(self::$userConnections[$userId]);
    }

    /**
     * 强制断开用户连接
     */
    public static function disconnectUser(int $userId, string $reason = '管理员操作'): void
    {
        if (!isset(self::$userConnections[$userId])) {
            return;
        }

        foreach (self::$userConnections[$userId] as $connectionId) {
            if (isset(self::$connections[$connectionId])) {
                $connection = self::$connections[$connectionId]['connection'];
                
                // 发送断开通知
                self::sendToConnection($connection, [
                    'type' => 'force_disconnect',
                    'reason' => $reason,
                    'timestamp' => time()
                ]);
                
                // 关闭连接
                $connection->close();
            }
        }

        Log::info('强制断开用户连接', [
            'user_id' => $userId,
            'reason' => $reason
        ]);
    }
}