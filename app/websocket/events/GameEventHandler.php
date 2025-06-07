<?php

namespace app\websocket\events;

use Workerman\Connection\TcpConnection;
use app\websocket\ConnectionManager;
use app\websocket\AuthManager;
use app\websocket\TableManager;
use app\websocket\RedisGameManager;
use think\facade\Log;

/**
 * 游戏事件处理器 - 简化版
 * 只处理台桌加入/离开、游戏状态查询等基础游戏相关消息
 * 适配 PHP 7.3 + ThinkPHP6
 */
class GameEventHandler
{
    /**
     * 处理游戏相关消息
     * @param TcpConnection $connection
     * @param array $message
     */
    public static function handle(TcpConnection $connection, array $message)
    {
        $messageType = $message['type'] ?? '';
        
        try {
            switch ($messageType) {
                case 'join_table':
                    self::handleJoinTable($connection, $message);
                    break;
                    
                case 'leave_table':
                    self::handleLeaveTable($connection, $message);
                    break;
                    
                case 'game_status':
                    self::handleGameStatus($connection, $message);
                    break;
                    
                default:
                    self::sendError($connection, '未知的游戏消息类型: ' . $messageType);
                    break;
            }
            
        } catch (\Exception $e) {
            Log::error('游戏事件处理异常', [
                'message_type' => $messageType,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            self::sendError($connection, '游戏事件处理失败');
        }
    }

    /**
     * 处理加入台桌
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleJoinTable(TcpConnection $connection, array $message)
    {
        $connectionId = spl_object_hash($connection);
        $userId = self::getConnectionUserId($connection);

        // 检查是否已认证
        if (!self::isAuthenticated($connection)) {
            self::sendError($connection, '请先进行身份认证');
            return;
        }

        // 验证参数
        if (!self::validateJoinTableMessage($message)) {
            self::sendError($connection, '缺少台桌ID参数或参数无效');
            return;
        }

        $tableId = (int)$message['table_id'];

        try {
            // 验证台桌是否存在且可用
            $table = TableManager::getTableInfo($tableId);
            
            if (!$table) {
                self::sendError($connection, '台桌不存在');
                return;
            }

            // 检查台桌状态
            if ($table['status'] != 1) {
                self::sendError($connection, '台桌当前不可用');
                return;
            }

            // 检查台桌类型（确保是骰宝游戏）
            if ($table['game_type'] != 9) {
                self::sendError($connection, '该台桌不是骰宝游戏台桌');
                return;
            }

            // 检查用户权限（如果需要）
            if (!AuthManager::checkUserPermission($userId, 'join_table', $tableId)) {
                self::sendError($connection, '您没有权限加入此台桌');
                return;
            }

            // 检查台桌连接数限制
            $currentConnections = ConnectionManager::getTableConnectionCount($tableId);
            $maxConnections = 100; // 可以从配置读取
            
            if ($currentConnections >= $maxConnections) {
                self::sendError($connection, '台桌人数已满，请稍后再试');
                return;
            }

            // 加入台桌
            $success = ConnectionManager::joinTable($connectionId, $tableId);
            
            if (!$success) {
                self::sendError($connection, '加入台桌失败，请重试');
                return;
            }

            // 获取台桌当前游戏状态
            $gameStatus = RedisGameManager::getGameStatus($tableId);
            
            // 获取台桌在线人数
            $onlineCount = ConnectionManager::getTableConnectionCount($tableId);

            // 发送加入成功响应
            self::sendSuccess($connection, 'join_table_success', [
                'table_id' => $tableId,
                'table_name' => $table['table_title'] ?? "台桌{$tableId}",
                'table_info' => [
                    'status' => $table['status'],
                    'run_status' => $table['run_status'] ?? 0,
                    'min_bet' => $table['min_bet'] ?? 10,
                    'max_bet' => $table['max_bet'] ?? 50000
                ],
                'game_status' => $gameStatus,
                'online_count' => $onlineCount,
                'join_time' => time()
            ], '成功加入台桌');

            // 广播用户加入消息给台桌其他用户
            self::broadcastToTable($tableId, [
                'type' => 'user_joined',
                'data' => [
                    'user_id' => $userId,
                    'table_id' => $tableId,
                    'online_count' => $onlineCount
                ],
                'timestamp' => time()
            ], [$connectionId]);

            // 记录日志
            Log::info('用户加入台桌', [
                'user_id' => $userId,
                'table_id' => $tableId,
                'table_name' => $table['table_title'] ?? '',
                'connection_id' => $connectionId,
                'online_count' => $onlineCount
            ]);

            echo "[" . date('Y-m-d H:i:s') . "] 用户加入台桌: UserID {$userId}, Table {$tableId}, 在线数: {$onlineCount}\n";

        } catch (\Exception $e) {
            Log::error('加入台桌异常', [
                'user_id' => $userId,
                'table_id' => $tableId,
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            self::sendError($connection, '加入台桌失败，系统异常');
        }
    }

    /**
     * 处理离开台桌
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleLeaveTable(TcpConnection $connection, array $message)
    {
        $connectionId = spl_object_hash($connection);
        $userId = self::getConnectionUserId($connection);
        $tableId = self::getConnectionTableId($connection);

        // 检查是否已认证
        if (!self::isAuthenticated($connection)) {
            self::sendError($connection, '请先进行身份认证');
            return;
        }

        // 检查是否在台桌中
        if (!$tableId) {
            self::sendError($connection, '您当前不在任何台桌中');
            return;
        }

        try {
            // 离开台桌
            ConnectionManager::leaveTable($connectionId, $tableId);
            
            // 获取更新后的在线人数
            $onlineCount = ConnectionManager::getTableConnectionCount($tableId);

            // 发送离开成功响应
            self::sendSuccess($connection, 'leave_table_success', [
                'table_id' => $tableId,
                'leave_time' => time()
            ], '成功离开台桌');

            // 广播用户离开消息给台桌其他用户
            self::broadcastToTable($tableId, [
                'type' => 'user_left',
                'data' => [
                    'user_id' => $userId,
                    'table_id' => $tableId,
                    'online_count' => $onlineCount
                ],
                'timestamp' => time()
            ]);

            // 记录日志
            Log::info('用户离开台桌', [
                'user_id' => $userId,
                'table_id' => $tableId,
                'connection_id' => $connectionId,
                'remaining_online' => $onlineCount
            ]);

            echo "[" . date('Y-m-d H:i:s') . "] 用户离开台桌: UserID {$userId}, Table {$tableId}, 剩余在线: {$onlineCount}\n";

        } catch (\Exception $e) {
            Log::error('离开台桌异常', [
                'user_id' => $userId,
                'table_id' => $tableId,
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            self::sendError($connection, '离开台桌失败，系统异常');
        }
    }

    /**
     * 处理游戏状态查询
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleGameStatus(TcpConnection $connection, array $message)
    {
        $userId = self::getConnectionUserId($connection);
        $tableId = self::getConnectionTableId($connection);

        // 检查是否已认证
        if (!self::isAuthenticated($connection)) {
            self::sendError($connection, '请先进行身份认证');
            return;
        }

        // 检查是否在台桌中
        if (!$tableId) {
            self::sendError($connection, '请先加入台桌');
            return;
        }

        try {
            // 获取游戏状态
            $gameStatus = RedisGameManager::getGameStatus($tableId);
            
            // 获取台桌基本信息
            $tableInfo = TableManager::getTableInfo($tableId);
            
            // 获取在线人数
            $onlineCount = ConnectionManager::getTableConnectionCount($tableId);
            
            // 获取最近游戏历史（最近5局）
            $recentHistory = TableManager::getTableHistory($tableId, 5);

            // 发送游戏状态响应
            self::sendSuccess($connection, 'game_status_response', [
                'table_id' => $tableId,
                'table_info' => [
                    'table_name' => $tableInfo['table_title'] ?? "台桌{$tableId}",
                    'status' => $tableInfo['status'] ?? 1,
                    'run_status' => $tableInfo['run_status'] ?? 0
                ],
                'game_status' => $gameStatus,
                'online_count' => $onlineCount,
                'recent_history' => $recentHistory,
                'query_time' => time()
            ], '游戏状态获取成功');

            // 记录查询日志（调试时使用）
            if (defined('APP_DEBUG') && APP_DEBUG) {
                Log::debug('用户查询游戏状态', [
                    'user_id' => $userId,
                    'table_id' => $tableId,
                    'game_status' => $gameStatus['status'] ?? 'unknown'
                ]);
            }

        } catch (\Exception $e) {
            Log::error('获取游戏状态异常', [
                'user_id' => $userId,
                'table_id' => $tableId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            self::sendError($connection, '获取游戏状态失败，系统异常');
        }
    }

    /**
     * 验证加入台桌消息格式
     * @param array $message
     * @return bool
     */
    private static function validateJoinTableMessage(array $message)
    {
        // 检查必需字段
        if (!isset($message['table_id'])) {
            return false;
        }

        // 检查数据类型和范围
        $tableId = $message['table_id'];
        
        if (!is_numeric($tableId)) {
            return false;
        }

        $tableId = (int)$tableId;
        
        if ($tableId <= 0 || $tableId > 9999) {
            return false;
        }

        return true;
    }

    /**
     * 获取连接的用户ID
     * @param TcpConnection $connection
     * @return int|null
     */
    private static function getConnectionUserId(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        return $connectionData ? $connectionData['user_id'] : null;
    }

    /**
     * 获取连接的台桌ID
     * @param TcpConnection $connection
     * @return int|null
     */
    private static function getConnectionTableId(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        return $connectionData ? $connectionData['table_id'] : null;
    }

    /**
     * 检查连接是否已认证
     * @param TcpConnection $connection
     * @return bool
     */
    private static function isAuthenticated(TcpConnection $connection)
    {
        $connectionId = spl_object_hash($connection);
        $connectionData = ConnectionManager::getConnection($connectionId);
        
        return $connectionData && $connectionData['auth_status'] === true;
    }

    /**
     * 广播消息到台桌
     * @param int $tableId
     * @param array $data
     * @param array $excludeConnections
     */
    private static function broadcastToTable($tableId, array $data, array $excludeConnections = [])
    {
        return ConnectionManager::broadcastToTable($tableId, $data, $excludeConnections);
    }

    /**
     * 发送成功响应
     * @param TcpConnection $connection
     * @param string $type
     * @param array $data
     * @param string $message
     */
    private static function sendSuccess(TcpConnection $connection, $type, array $data = [], $message = 'success')
    {
        $response = [
            'type' => $type,
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ];

        self::sendToConnection($connection, $response);
    }

    /**
     * 发送错误响应
     * @param TcpConnection $connection
     * @param string $message
     * @param string $type
     */
    private static function sendError(TcpConnection $connection, $message, $type = 'game_error')
    {
        $response = [
            'type' => $type,
            'success' => false,
            'message' => $message,
            'timestamp' => time()
        ];

        self::sendToConnection($connection, $response);
    }

    /**
     * 发送消息到连接
     * @param TcpConnection $connection
     * @param array $data
     */
    private static function sendToConnection(TcpConnection $connection, array $data)
    {
        try {
            $message = json_encode($data, JSON_UNESCAPED_UNICODE);
            $connection->send($message);
        } catch (\Exception $e) {
            echo "[ERROR] 发送游戏消息失败: " . $e->getMessage() . "\n";
            Log::error('发送游戏消息失败', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    /**
     * 获取台桌统计信息
     * @param int $tableId
     * @return array
     */
    public static function getTableStats($tableId)
    {
        try {
            return [
                'table_id' => $tableId,
                'online_count' => ConnectionManager::getTableConnectionCount($tableId),
                'game_status' => RedisGameManager::getGameStatus($tableId),
                'table_info' => TableManager::getTableInfo($tableId),
                'update_time' => time()
            ];
            
        } catch (\Exception $e) {
            Log::error('获取台桌统计失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'table_id' => $tableId,
                'online_count' => 0,
                'game_status' => null,
                'table_info' => null,
                'update_time' => time(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 批量获取台桌统计
     * @param array $tableIds
     * @return array
     */
    public static function batchGetTableStats(array $tableIds)
    {
        $results = [];
        
        foreach ($tableIds as $tableId) {
            $results[$tableId] = self::getTableStats($tableId);
        }
        
        return $results;
    }

    /**
     * 强制清空台桌所有用户
     * @param int $tableId
     * @param string $reason
     * @return int 清空的连接数
     */
    public static function clearTableUsers($tableId, $reason = '台桌维护')
    {
        try {
            // 发送台桌清空通知
            $message = [
                'type' => 'table_cleared',
                'success' => false,
                'message' => $reason,
                'data' => [
                    'table_id' => $tableId,
                    'clear_time' => time()
                ],
                'timestamp' => time()
            ];

            // 广播到台桌所有用户
            $sentCount = self::broadcastToTable($tableId, $message);
            
            // 强制离开台桌
            ConnectionManager::clearTableConnections($tableId);

            // 记录日志
            Log::warning('台桌用户被清空', [
                'table_id' => $tableId,
                'reason' => $reason,
                'affected_connections' => $sentCount
            ]);

            return $sentCount;

        } catch (\Exception $e) {
            Log::error('清空台桌用户失败', [
                'table_id' => $tableId,
                'reason' => $reason,
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
}