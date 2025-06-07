<?php

namespace app\websocket\events;

use Workerman\Connection\TcpConnection;
use app\websocket\ConnectionManager;
use app\websocket\MessageHandler;
use app\websocket\TableManager;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * 游戏事件处理器
 * 处理台桌加入/离开、游戏状态查询等消息
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
        $messageType = $message['type'];
        
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
                MessageHandler::sendError($connection, '未知的游戏消息类型');
                break;
        }
    }

    /**
     * 处理加入台桌
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleJoinTable(TcpConnection $connection, array $message)
    {
        // 检查是否已认证
        if (!MessageHandler::isAuthenticated($connection)) {
            MessageHandler::sendError($connection, '请先进行身份认证');
            return;
        }

        // 验证参数
        if (!MessageHandler::validateMessage($message, ['table_id'])) {
            MessageHandler::sendError($connection, '缺少台桌ID参数');
            return;
        }

        $tableId = (int)$message['table_id'];
        $connectionId = spl_object_hash($connection);
        $userId = MessageHandler::getConnectionUserId($connection);

        try {
            // 验证台桌是否存在且可用
            $table = TableManager::getTableInfo($tableId);
            
            if (!$table) {
                MessageHandler::sendError($connection, '台桌不存在或已关闭');
                return;
            }

            // 检查台桌状态
            if ($table['status'] != 1) {
                MessageHandler::sendError($connection, '台桌当前不可用');
                return;
            }

            // 加入台桌
            $success = ConnectionManager::joinTable($connectionId, $tableId);
            
            if (!$success) {
                MessageHandler::sendError($connection, '加入台桌失败');
                return;
            }

            // 获取台桌当前游戏状态
            $gameStatus = TableManager::getGameStatus($tableId);

            // 发送加入成功响应
            MessageHandler::sendSuccess($connection, 'join_table_success', [
                'table_id' => $tableId,
                'table_info' => $table,
                'game_status' => $gameStatus,
                'online_count' => ConnectionManager::getTableConnectionCount($tableId)
            ], '成功加入台桌');

            // 广播用户加入消息给台桌其他用户
            MessageHandler::broadcastToTable($tableId, [
                'type' => 'user_joined',
                'user_id' => $userId,
                'table_id' => $tableId,
                'online_count' => ConnectionManager::getTableConnectionCount($tableId)
            ], [$connectionId]);

            // 记录日志
            Log::info('用户加入台桌', [
                'user_id' => $userId,
                'table_id' => $tableId,
                'connection_id' => $connectionId
            ]);

        } catch (\Exception $e) {
            Log::error('加入台桌异常: ' . $e->getMessage(), [
                'user_id' => $userId,
                'table_id' => $tableId
            ]);
            
            MessageHandler::sendError($connection, '加入台桌失败');
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
        $userId = MessageHandler::getConnectionUserId($connection);
        $tableId = MessageHandler::getConnectionTableId($connection);

        if (!$tableId) {
            MessageHandler::sendError($connection, '您当前不在任何台桌中');
            return;
        }

        try {
            // 离开台桌
            ConnectionManager::leaveTable($connectionId, $tableId);

            // 发送离开成功响应
            MessageHandler::sendSuccess($connection, 'leave_table_success', [
                'table_id' => $tableId
            ], '成功离开台桌');

            // 广播用户离开消息
            MessageHandler::broadcastToTable($tableId, [
                'type' => 'user_left',
                'user_id' => $userId,
                'table_id' => $tableId,
                'online_count' => ConnectionManager::getTableConnectionCount($tableId)
            ]);

            // 记录日志
            Log::info('用户离开台桌', [
                'user_id' => $userId,
                'table_id' => $tableId,
                'connection_id' => $connectionId
            ]);

        } catch (\Exception $e) {
            Log::error('离开台桌异常: ' . $e->getMessage(), [
                'user_id' => $userId,
                'table_id' => $tableId
            ]);
            
            MessageHandler::sendError($connection, '离开台桌失败');
        }
    }

    /**
     * 处理游戏状态查询
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleGameStatus(TcpConnection $connection, array $message)
    {
        $tableId = MessageHandler::getConnectionTableId($connection);

        if (!$tableId) {
            MessageHandler::sendError($connection, '请先加入台桌');
            return;
        }

        try {
            // 获取游戏状态
            $gameStatus = TableManager::getGameStatus($tableId);
            
            // 获取台桌统计信息
            $tableStats = TableManager::getTableStats($tableId);
            
            // 发送游戏状态响应
            MessageHandler::sendSuccess($connection, 'game_status_response', [
                'table_id' => $tableId,
                'game_status' => $gameStatus,
                'table_stats' => $tableStats,
                'online_count' => ConnectionManager::getTableConnectionCount($tableId)
            ], '游戏状态获取成功');

        } catch (\Exception $e) {
            Log::error('获取游戏状态异常: ' . $e->getMessage(), [
                'table_id' => $tableId
            ]);
            
            MessageHandler::sendError($connection, '获取游戏状态失败');
        }
    }
}