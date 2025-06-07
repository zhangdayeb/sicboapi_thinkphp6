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
 * 投注事件处理器
 * 处理投注相关的WebSocket消息
 */
class BetEventHandler
{
    /**
     * 处理投注相关消息
     * @param TcpConnection $connection
     * @param array $message
     */
    public static function handle(TcpConnection $connection, array $message)
    {
        $messageType = $message['type'];
        
        switch ($messageType) {
            case 'bet_update':
                self::handleBetUpdate($connection, $message);
                break;
                
            case 'get_current_bets':
                self::handleGetCurrentBets($connection, $message);
                break;
                
            case 'get_bet_history':
                self::handleGetBetHistory($connection, $message);
                break;
                
            case 'cancel_bets':
                self::handleCancelBets($connection, $message);
                break;
                
            default:
                MessageHandler::sendError($connection, '未知的投注消息类型');
                break;
        }
    }

    /**
     * 处理投注更新通知
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleBetUpdate(TcpConnection $connection, array $message)
    {
        $userId = MessageHandler::getConnectionUserId($connection);
        $tableId = MessageHandler::getConnectionTableId($connection);

        if (!$userId || !$tableId) {
            MessageHandler::sendError($connection, '请先登录并加入台桌');
            return;
        }

        try {
            // 获取用户当前投注
            $currentBets = self::getUserCurrentBets($userId, $tableId);
            
            // 计算投注总额
            $totalAmount = array_sum(array_column($currentBets, 'bet_amount'));
            
            // 发送投注更新响应
            MessageHandler::sendSuccess($connection, 'bet_update_response', [
                'table_id' => $tableId,
                'current_bets' => $currentBets,
                'total_amount' => $totalAmount,
                'bet_count' => count($currentBets)
            ], '投注信息已更新');

            // 广播投注统计更新给台桌其他用户（不包含具体投注内容）
            MessageHandler::broadcastToTable($tableId, [
                'type' => 'bet_stats_update',
                'table_id' => $tableId,
                'has_new_activity' => !empty($currentBets),
                'timestamp' => time()
            ], [spl_object_hash($connection)]);

        } catch (\Exception $e) {
            Log::error('投注更新处理异常: ' . $e->getMessage(), [
                'user_id' => $userId,
                'table_id' => $tableId
            ]);
            
            MessageHandler::sendError($connection, '投注更新失败');
        }
    }

    /**
     * 处理获取当前投注请求
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleGetCurrentBets(TcpConnection $connection, array $message)
    {
        $userId = MessageHandler::getConnectionUserId($connection);
        $tableId = MessageHandler::getConnectionTableId($connection);

        if (!$userId || !$tableId) {
            MessageHandler::sendError($connection, '请先登录并加入台桌');
            return;
        }

        try {
            // 获取当前游戏局号
            $gameStatus = TableManager::getGameStatus($tableId);
            $gameNumber = $gameStatus['current_game']['game_number'] ?? null;

            if (!$gameNumber) {
                MessageHandler::sendSuccess($connection, 'current_bets_response', [
                    'table_id' => $tableId,
                    'game_number' => null,
                    'bets' => [],
                    'total_amount' => 0
                ], '当前没有进行中的游戏');
                return;
            }

            // 获取用户当前投注
            $currentBets = self::getUserCurrentBetsByGame($userId, $tableId, $gameNumber);
            $totalAmount = array_sum(array_column($currentBets, 'bet_amount'));

            // 发送当前投注响应
            MessageHandler::sendSuccess($connection, 'current_bets_response', [
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'bets' => $currentBets,
                'total_amount' => $totalAmount,
                'bet_count' => count($currentBets)
            ], '当前投注获取成功');

        } catch (\Exception $e) {
            Log::error('获取当前投注异常: ' . $e->getMessage(), [
                'user_id' => $userId,
                'table_id' => $tableId
            ]);
            
            MessageHandler::sendError($connection, '获取当前投注失败');
        }
    }

    /**
     * 处理获取投注历史请求
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleGetBetHistory(TcpConnection $connection, array $message)
    {
        $userId = MessageHandler::getConnectionUserId($connection);

        if (!$userId) {
            MessageHandler::sendError($connection, '请先登录');
            return;
        }

        try {
            $page = (int)($message['page'] ?? 1);
            $limit = min((int)($message['limit'] ?? 20), 100); // 最大100条
            $tableId = $message['table_id'] ?? null;

            // 获取投注历史
            $history = self::getUserBetHistory($userId, $page, $limit, $tableId);

            // 发送投注历史响应
            MessageHandler::sendSuccess($connection, 'bet_history_response', [
                'page' => $page,
                'limit' => $limit,
                'history' => $history['data'],
                'total' => $history['total'],
                'has_more' => $history['has_more']
            ], '投注历史获取成功');

        } catch (\Exception $e) {
            Log::error('获取投注历史异常: ' . $e->getMessage(), [
                'user_id' => $userId
            ]);
            
            MessageHandler::sendError($connection, '获取投注历史失败');
        }
    }

    /**
     * 处理取消投注请求
     * @param TcpConnection $connection
     * @param array $message
     */
    private static function handleCancelBets(TcpConnection $connection, array $message)
    {
        $userId = MessageHandler::getConnectionUserId($connection);
        $tableId = MessageHandler::getConnectionTableId($connection);

        if (!$userId || !$tableId) {
            MessageHandler::sendError($connection, '请先登录并加入台桌');
            return;
        }

        try {
            // 检查游戏状态是否允许取消投注
            $gameStatus = TableManager::getGameStatus($tableId);
            
            if ($gameStatus['status'] !== 'betting') {
                MessageHandler::sendError($connection, '当前游戏状态不允许取消投注');
                return;
            }

            $gameNumber = $gameStatus['current_game']['game_number'] ?? null;
            
            if (!$gameNumber) {
                MessageHandler::sendError($connection, '没有进行中的游戏');
                return;
            }

            // 取消用户当前游戏的所有投注
            $cancelResult = self::cancelUserBets($userId, $tableId, $gameNumber);

            if ($cancelResult['success']) {
                // 发送取消成功响应
                MessageHandler::sendSuccess($connection, 'cancel_bets_success', [
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'canceled_amount' => $cancelResult['canceled_amount'],
                    'new_balance' => $cancelResult['new_balance']
                ], '投注已取消');

                // 广播投注取消消息
                MessageHandler::broadcastToTable($tableId, [
                    'type' => 'bet_canceled',
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'timestamp' => time()
                ], [spl_object_hash($connection)]);

            } else {
                MessageHandler::sendError($connection, $cancelResult['error'] ?? '取消投注失败');
            }

        } catch (\Exception $e) {
            Log::error('取消投注异常: ' . $e->getMessage(), [
                'user_id' => $userId,
                'table_id' => $tableId
            ]);
            
            MessageHandler::sendError($connection, '取消投注失败');
        }
    }

    /**
     * 获取用户当前投注
     * @param int $userId
     * @param int $tableId
     * @return array
     */
    private static function getUserCurrentBets($userId, $tableId)
    {
        try {
            // 获取当前游戏状态
            $gameStatus = TableManager::getGameStatus($tableId);
            $gameNumber = $gameStatus['current_game']['game_number'] ?? null;
            
            if (!$gameNumber) {
                return [];
            }

            return self::getUserCurrentBetsByGame($userId, $tableId, $gameNumber);
            
        } catch (\Exception $e) {
            Log::error('获取用户当前投注失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 根据游戏局号获取用户投注
     * @param int $userId
     * @param int $tableId
     * @param string $gameNumber
     * @return array
     */
    private static function getUserCurrentBetsByGame($userId, $tableId, $gameNumber)
    {
        try {
            $bets = Db::table('sicbo_bet_records')
                ->where('user_id', $userId)
                ->where('table_id', $tableId)
                ->where('game_number', $gameNumber)
                ->where('settle_status', 0)
                ->field('id,bet_type,bet_amount,odds,potential_win,bet_time')
                ->order('bet_time desc')
                ->select();
                
            return $bets ? $bets->toArray() : [];
            
        } catch (\Exception $e) {
            Log::error('获取用户游戏投注失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取用户投注历史
     * @param int $userId
     * @param int $page
     * @param int $limit
     * @param int|null $tableId
     * @return array
     */
    private static function getUserBetHistory($userId, $page, $limit, $tableId = null)
    {
        try {
            $query = Db::table('sicbo_bet_records')
                ->where('user_id', $userId);
                
            if ($tableId) {
                $query->where('table_id', $tableId);
            }

            // 获取总数
            $total = $query->count();

            // 获取分页数据
            $offset = ($page - 1) * $limit;
            $data = $query->field('id,table_id,game_number,bet_type,bet_amount,odds,is_win,win_amount,settle_status,bet_time,settle_time')
                ->order('bet_time desc')
                ->limit($offset, $limit)
                ->select();

            return [
                'data' => $data ? $data->toArray() : [],
                'total' => $total,
                'has_more' => $total > ($page * $limit)
            ];
            
        } catch (\Exception $e) {
            Log::error('获取用户投注历史失败: ' . $e->getMessage());
            return [
                'data' => [],
                'total' => 0,
                'has_more' => false
            ];
        }
    }

    /**
     * 取消用户投注
     * @param int $userId
     * @param int $tableId
     * @param string $gameNumber
     * @return array
     */
    private static function cancelUserBets($userId, $tableId, $gameNumber)
    {
        try {
            Db::startTrans();

            // 获取待取消的投注
            $bets = Db::table('sicbo_bet_records')
                ->where('user_id', $userId)
                ->where('table_id', $tableId)
                ->where('game_number', $gameNumber)
                ->where('settle_status', 0)
                ->select();

            if (empty($bets)) {
                Db::rollback();
                return ['success' => false, 'error' => '没有可取消的投注'];
            }

            $totalAmount = 0;
            foreach ($bets as $bet) {
                $totalAmount += $bet['bet_amount'];
            }

            // 删除投注记录
            Db::table('sicbo_bet_records')
                ->where('user_id', $userId)
                ->where('table_id', $tableId)
                ->where('game_number', $gameNumber)
                ->where('settle_status', 0)
                ->delete();

            // 返还用户余额
            $newBalance = self::refundUserBalance($userId, $totalAmount, $gameNumber);

            Db::commit();

            return [
                'success' => true,
                'canceled_amount' => $totalAmount,
                'new_balance' => $newBalance
            ];

        } catch (\Exception $e) {
            Db::rollback();
            Log::error('取消用户投注失败: ' . $e->getMessage());
            return ['success' => false, 'error' => '取消投注失败'];
        }
    }

    /**
     * 返还用户余额
     * @param int $userId
     * @param float $amount
     * @param string $gameNumber
     * @return float
     */
    private static function refundUserBalance($userId, $amount, $gameNumber)
    {
        try {
            // 更新用户余额
            $userBalance = Db::table('user_balance')->where('user_id', $userId)->find();
            
            if (!$userBalance) {
                throw new \Exception('用户余额记录不存在');
            }

            $beforeBalance = $userBalance['balance'];
            $afterBalance = $beforeBalance + $amount;

            // 更新余额
            Db::table('user_balance')
                ->where('user_id', $userId)
                ->update([
                    'balance' => $afterBalance,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            // 记录余额变动日志
            Db::table('user_balance_log')->insert([
                'user_id' => $userId,
                'type' => 'sicbo_bet_cancel',
                'amount' => $amount,
                'before_balance' => $beforeBalance,
                'after_balance' => $afterBalance,
                'remark' => "骰宝投注取消退款-局号:{$gameNumber}",
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // 清除余额缓存
            Cache::delete("user_balance_{$userId}");

            return $afterBalance;

        } catch (\Exception $e) {
            Log::error('返还用户余额失败: ' . $e->getMessage());
            throw $e;
        }
    }
}