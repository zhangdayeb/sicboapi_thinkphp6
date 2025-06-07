<?php

namespace app\websocket\events;

use Workerman\Connection\TcpConnection;
use app\websocket\ConnectionManager;
use app\websocket\RedisGameManager;
use think\facade\Log;
use think\facade\Db;

/**
 * 游戏事件处理器 - 简化版
 * 只保留4个核心方法，专注于台桌加入/离开、游戏状态查询等基础游戏功能
 * 集成简单的台桌验证逻辑，删除统计功能、批量操作等复杂功能
 * 适配 PHP 7.3 + ThinkPHP6
 */
class GameEventHandler
{
    /**
     * 1. 处理游戏相关消息（主入口）
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
     * 2. 处理加入台桌
     * @param TcpConnection $connection
     * @param array $message
     */
    public static function handleJoinTable(TcpConnection $connection, array $message)
    {
        $connectionId = spl_object_hash($connection);
        $userId = self::getConnectionUserId($connection);

        // 验证参数
        if (!self::validateJoinTableMessage($message)) {
            self::sendError($connection, '缺少台桌ID参数或参数无效');
            return;
        }

        $tableId = (int)$message['table_id'];

        try {
            // 验证台桌是否存在且可用
            if (!self::validateTable($tableId)) {
                self::sendError($connection, '台桌不存在或当前不可用');
                return;
            }

            // 检查台桌连接数限制
            $currentConnections = ConnectionManager::getTableConnectionCount($tableId);
            $maxConnections = 100; // 可配置
            
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

            // 获取台桌基本信息
            $tableInfo = self::getTableInfo($tableId);

            // 发送加入成功响应
            self::sendSuccess($connection, 'join_table_success', [
                'table_id' => $tableId,
                'table_name' => $tableInfo['table_title'] ?? "台桌{$tableId}",
                'table_info' => [
                    'status' => $tableInfo['status'] ?? 1,
                    'run_status' => $tableInfo['run_status'] ?? 0,
                    'min_bet' => $tableInfo['min_bet'] ?? 10,
                    'max_bet' => $tableInfo['max_bet'] ?? 50000
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
                'table_name' => $tableInfo['table_title'] ?? '',
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
     * 3. 处理离开台桌
     * @param TcpConnection $connection
     * @param array $message
     */
    public static function handleLeaveTable(TcpConnection $connection, array $message)
    {
        $connectionId = spl_object_hash($connection);
        $userId = self::getConnectionUserId($connection);
        $tableId = self::getConnectionTableId($connection);

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
     * 4. 处理游戏状态查询
     * @param TcpConnection $connection
     * @param array $message
     */
    public static function handleGameStatus(TcpConnection $connection, array $message)
    {
        $userId = self::getConnectionUserId($connection);
        $tableId = self::getConnectionTableId($connection);

        // 检查是否在台桌中
        if (!$tableId) {
            self::sendError($connection, '请先加入台桌');
            return;
        }

        try {
            // 获取游戏状态
            $gameStatus = RedisGameManager::getGameStatus($tableId);
            
            // 获取台桌基本信息
            $tableInfo = self::getTableInfo($tableId);
            
            // 获取在线人数
            $onlineCount = ConnectionManager::getTableConnectionCount($tableId);
            
            // 获取最近游戏历史（最近5局）
            $recentHistory = RedisGameManager::getRecentResults($tableId, 5);

            // 发送游戏状态响应
            self::sendSuccess($connection, 'game_status_response', [
                'table_id' => $tableId,
                'table_info' => [
                    'table_name' => $tableInfo['table_title'] ?? "台桌{$tableId}",
                    'status' => $tableInfo['status'] ?? 1,
                    'run_status' => $tableInfo['run_status'] ?? 0,
                    'min_bet' => $tableInfo['min_bet'] ?? 10,
                    'max_bet' => $tableInfo['max_bet'] ?? 50000
                ],
                'game_status' => $gameStatus,
                'online_count' => $onlineCount,
                'recent_history' => $recentHistory,
                'query_time' => time()
            ], '游戏状态获取成功');

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

    // ========================================
    // 私有辅助方法
    // ========================================

    /**
     * 验证台桌是否存在且可用（集成简单台桌验证）
     * @param int $tableId
     * @return bool
     */
    private static function validateTable($tableId)
    {
        try {
            $table = Db::name('dianji_table')
                ->where('id', $tableId)
                ->where('game_type', 9) // 骰宝游戏类型
                ->where('status', 1)    // 开放状态
                ->find();
                
            return $table !== null;
            
        } catch (\Exception $e) {
            Log::error('验证台桌失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 获取台桌基本信息（集成简单台桌信息获取）
     * @param int $tableId
     * @return array
     */
    private static function getTableInfo($tableId)
    {
        try {
            $table = Db::name('dianji_table')
                ->where('id', $tableId)
                ->where('game_type', 9)
                ->find();
                
            if (!$table) {
                return [
                    'id' => $tableId,
                    'table_title' => "台桌{$tableId}",
                    'status' => 0,
                    'run_status' => 0,
                    'min_bet' => 10,
                    'max_bet' => 50000
                ];
            }

            return [
                'id' => (int)$table['id'],
                'table_title' => $table['table_title'] ?? "台桌{$tableId}",
                'status' => (int)($table['status'] ?? 0),
                'run_status' => (int)($table['run_status'] ?? 0),
                'min_bet' => (int)($table['min_bet'] ?? 10),
                'max_bet' => (int)($table['max_bet'] ?? 50000),
                'game_config' => $table['game_config'] ? json_decode($table['game_config'], true) : []
            ];
            
        } catch (\Exception $e) {
            Log::error('获取台桌信息失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            
            // 返回默认信息
            return [
                'id' => $tableId,
                'table_title' => "台桌{$tableId}",
                'status' => 0,
                'run_status' => 0,
                'min_bet' => 10,
                'max_bet' => 50000
            ];
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
     * 广播消息到台桌
     * @param int $tableId
     * @param array $data
     * @param array $excludeConnections
     * @return int
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
}