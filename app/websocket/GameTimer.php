<?php

namespace app\websocket;

use app\websocket\RedisGameManager;
use app\websocket\NotificationSender;
use app\websocket\ConnectionManager;
use think\facade\Log;
use think\facade\Config;

/**
 * 游戏定时器 - 新增
 * 负责定时检查Redis游戏状态并触发相应的WebSocket推送
 * 适配 PHP 7.3 + ThinkPHP6
 */
class GameTimer
{
    /**
     * 游戏状态常量
     */
    const STATUS_WAITING = 'waiting';
    const STATUS_BETTING = 'betting';
    const STATUS_DEALING = 'dealing';
    const STATUS_RESULT = 'result';

    /**
     * 上次检查的游戏状态缓存
     * @var array
     */
    private static $lastGameStates = [];

    /**
     * 上次发送倒计时的时间点缓存
     * @var array
     */
    private static $lastCountdownSent = [];

    /**
     * 已处理的开奖结果缓存
     * @var array
     */
    private static $processedResults = [];

    /**
     * 已处理的中奖信息缓存
     * @var array
     */
    private static $processedWinInfo = [];

    /**
     * 配置信息
     * @var array
     */
    private static $config = [];

    /**
     * 初始化定时器
     */
    public static function init()
    {
        self::$config = Config::get('websocket.game', []);
        self::$lastGameStates = [];
        self::$lastCountdownSent = [];
        self::$processedResults = [];
        self::$processedWinInfo = [];
        
        echo "[GameTimer] 游戏定时器初始化完成\n";
    }

    /**
     * 检查游戏状态（定时器调用的主方法）
     * 每秒执行一次
     */
    public static function checkGameStatus()
    {
        try {
            // 获取所有活跃台桌
            $activeTables = self::getActiveTables();
            
            if (empty($activeTables)) {
                return;
            }

            // 遍历每个台桌检查状态
            foreach ($activeTables as $tableId) {
                self::checkSingleTableStatus($tableId);
            }

            // 清理过期的缓存数据
            self::cleanupExpiredCache();

        } catch (\Exception $e) {
            echo "[ERROR] 游戏状态检查异常: " . $e->getMessage() . "\n";
            Log::error('游戏状态检查异常', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * 检查单个台桌的游戏状态
     * @param int $tableId
     */
    private static function checkSingleTableStatus($tableId)
    {
        try {
            // 检查台桌是否有在线用户
            $onlineCount = ConnectionManager::getTableConnectionCount($tableId);
            if ($onlineCount === 0) {
                return; // 没有用户在线，跳过检查
            }

            // 获取当前游戏状态
            $currentGameStatus = RedisGameManager::getGameStatus($tableId);
            if (!$currentGameStatus) {
                return; // 没有游戏状态，跳过
            }

            // 获取上次的游戏状态
            $lastGameStatus = self::$lastGameStates[$tableId] ?? null;

            // 检查游戏状态变化
            if (self::hasGameStatusChanged($tableId, $currentGameStatus, $lastGameStatus)) {
                self::handleGameStatusChange($tableId, $currentGameStatus, $lastGameStatus);
            }

            // 检查倒计时推送
            if ($currentGameStatus['status'] === self::STATUS_BETTING) {
                self::handleCountdownCheck($tableId, $currentGameStatus);
            }

            // 检查开奖结果
            self::checkGameResult($tableId, $currentGameStatus);

            // 检查中奖信息
            self::checkWinInfo($tableId, $currentGameStatus);

            // 更新缓存的游戏状态
            self::$lastGameStates[$tableId] = $currentGameStatus;

        } catch (\Exception $e) {
            echo "[ERROR] 检查台桌{$tableId}状态异常: " . $e->getMessage() . "\n";
            Log::error('检查台桌状态异常', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 检查游戏状态是否发生变化
     * @param int $tableId
     * @param array $current
     * @param array|null $last
     * @return bool
     */
    private static function hasGameStatusChanged($tableId, $current, $last)
    {
        if (!$last) {
            return true; // 第一次检查，算作变化
        }

        // 检查关键状态是否变化
        $keyFields = ['status', 'game_number', 'round_number'];
        
        foreach ($keyFields as $field) {
            if (($current[$field] ?? '') !== ($last[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 处理游戏状态变化
     * @param int $tableId
     * @param array $currentStatus
     * @param array|null $lastStatus
     */
    private static function handleGameStatusChange($tableId, $currentStatus, $lastStatus)
    {
        $currentState = $currentStatus['status'] ?? '';
        $lastState = $lastStatus['status'] ?? '';

        echo "[GameTimer] 台桌{$tableId}状态变化: {$lastState} -> {$currentState}\n";

        try {
            // 根据状态变化触发相应推送
            switch ($currentState) {
                case self::STATUS_BETTING:
                    // 开始投注
                    if ($lastState !== self::STATUS_BETTING) {
                        self::handleGameStart($tableId, $currentStatus);
                    }
                    break;

                case self::STATUS_DEALING:
                    // 停止投注，开始开奖
                    if ($lastState === self::STATUS_BETTING) {
                        self::handleGameEnd($tableId, $currentStatus);
                    }
                    break;

                case self::STATUS_RESULT:
                    // 结果公布状态
                    break;

                case self::STATUS_WAITING:
                    // 等待下一局
                    break;
            }

        } catch (\Exception $e) {
            Log::error('处理游戏状态变化异常', [
                'table_id' => $tableId,
                'current_state' => $currentState,
                'last_state' => $lastState,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 处理游戏开始
     * @param int $tableId
     * @param array $gameStatus
     */
    private static function handleGameStart($tableId, $gameStatus)
    {
        try {
            // 发送游戏开始推送
            $sentCount = NotificationSender::sendGameStart($tableId, [
                'game_number' => $gameStatus['game_number'] ?? '',
                'round_number' => $gameStatus['round_number'] ?? 1,
                'countdown' => $gameStatus['total_time'] ?? 30,
                'betting_end_time' => $gameStatus['betting_end_time'] ?? (time() + 30)
            ]);

            echo "[GameTimer] 发送游戏开始推送: 台桌{$tableId}, 发送数{$sentCount}\n";

            // 初始化倒计时缓存
            self::$lastCountdownSent[$tableId] = [];

        } catch (\Exception $e) {
            Log::error('处理游戏开始异常', [
                'table_id' => $tableId,
                'game_status' => $gameStatus,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 处理游戏结束
     * @param int $tableId
     * @param array $gameStatus
     */
    private static function handleGameEnd($tableId, $gameStatus)
    {
        try {
            // 发送停止投注推送
            $sentCount = NotificationSender::sendGameEnd($tableId, [
                'game_number' => $gameStatus['game_number'] ?? '',
                'end_time' => time()
            ]);

            echo "[GameTimer] 发送停止投注推送: 台桌{$tableId}, 发送数{$sentCount}\n";

        } catch (\Exception $e) {
            Log::error('处理游戏结束异常', [
                'table_id' => $tableId,
                'game_status' => $gameStatus,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 处理倒计时检查
     * @param int $tableId
     * @param array $gameStatus
     */
    private static function handleCountdownCheck($tableId, $gameStatus)
    {
        try {
            $bettingEndTime = $gameStatus['betting_end_time'] ?? 0;
            $totalTime = $gameStatus['total_time'] ?? 30;
            
            if ($bettingEndTime <= 0) {
                return;
            }

            $currentTime = time();
            $remainingTime = $bettingEndTime - $currentTime;

            // 如果倒计时已结束，不再推送
            if ($remainingTime < 0) {
                return;
            }

            // 检查是否需要推送倒计时
            if (self::shouldSendCountdown($tableId, $remainingTime, $totalTime)) {
                self::sendCountdownNotification($tableId, $remainingTime, $totalTime, $gameStatus);
            }

        } catch (\Exception $e) {
            Log::error('倒计时检查异常', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 判断是否应该发送倒计时推送
     * @param int $tableId
     * @param int $remainingTime
     * @param int $totalTime
     * @return bool
     */
    private static function shouldSendCountdown($tableId, $remainingTime, $totalTime)
    {
        $strategy = self::$config['countdown_strategy'] ?? [
            'normal_intervals' => [30, 20, 10],
            'final_countdown' => [5, 4, 3, 2, 1, 0]
        ];

        $normalIntervals = $strategy['normal_intervals'] ?? [30, 20, 10];
        $finalCountdown = $strategy['final_countdown'] ?? [5, 4, 3, 2, 1, 0];

        $lastSent = self::$lastCountdownSent[$tableId] ?? [];

        // 检查正常间隔推送 (30, 20, 10)
        if (in_array($remainingTime, $normalIntervals)) {
            if (!isset($lastSent[$remainingTime])) {
                return true;
            }
        }

        // 检查最后倒计时推送 (5, 4, 3, 2, 1, 0)
        if (in_array($remainingTime, $finalCountdown)) {
            if (!isset($lastSent[$remainingTime])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 发送倒计时通知
     * @param int $tableId
     * @param int $remainingTime
     * @param int $totalTime
     * @param array $gameStatus
     */
    private static function sendCountdownNotification($tableId, $remainingTime, $totalTime, $gameStatus)
    {
        try {
            $sentCount = NotificationSender::sendCountdown($tableId, [
                'game_number' => $gameStatus['game_number'] ?? '',
                'remaining_time' => $remainingTime,
                'total_time' => $totalTime
            ]);

            // 记录已发送的倒计时
            if (!isset(self::$lastCountdownSent[$tableId])) {
                self::$lastCountdownSent[$tableId] = [];
            }
            self::$lastCountdownSent[$tableId][$remainingTime] = time();

            echo "[GameTimer] 发送倒计时推送: 台桌{$tableId}, 剩余{$remainingTime}秒, 发送数{$sentCount}\n";

        } catch (\Exception $e) {
            Log::error('发送倒计时通知异常', [
                'table_id' => $tableId,
                'remaining_time' => $remainingTime,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 检查开奖结果
     * @param int $tableId
     * @param array $gameStatus
     */
    private static function checkGameResult($tableId, $gameStatus)
    {
        try {
            $gameNumber = $gameStatus['game_number'] ?? '';
            if (empty($gameNumber)) {
                return;
            }

            // 检查是否已处理过此结果
            $resultKey = "{$tableId}:{$gameNumber}";
            if (isset(self::$processedResults[$resultKey])) {
                return;
            }

            // 获取开奖结果
            $gameResult = RedisGameManager::getGameResult($tableId, $gameNumber);
            if (!$gameResult) {
                return; // 还没有结果
            }

            // 发送开奖结果推送
            $sentCount = NotificationSender::sendGameResult($tableId, $gameStatus, $gameResult);

            // 标记为已处理
            self::$processedResults[$resultKey] = time();

            echo "[GameTimer] 发送开奖结果推送: 台桌{$tableId}, 游戏{$gameNumber}, 发送数{$sentCount}\n";

        } catch (\Exception $e) {
            Log::error('检查开奖结果异常', [
                'table_id' => $tableId,
                'game_status' => $gameStatus,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 检查中奖信息
     * @param int $tableId
     * @param array $gameStatus
     */
    private static function checkWinInfo($tableId, $gameStatus)
    {
        try {
            $gameNumber = $gameStatus['game_number'] ?? '';
            if (empty($gameNumber)) {
                return;
            }

            // 检查是否已处理过此游戏的中奖信息
            $winKey = "{$tableId}:{$gameNumber}";
            if (isset(self::$processedWinInfo[$winKey])) {
                return;
            }

            // 获取台桌所有在线用户的中奖信息
            $onlineUsers = ConnectionManager::getTableUsers($tableId);
            if (empty($onlineUsers)) {
                return;
            }

            $totalWinNotifications = 0;

            foreach ($onlineUsers as $userId) {
                $winInfo = RedisGameManager::getWinInfo($userId, $gameNumber);
                if ($winInfo && ($winInfo['win_amount'] ?? 0) > 0) {
                    // 发送个人中奖信息
                    $sentCount = NotificationSender::sendWinInfo($userId, $winInfo);
                    $totalWinNotifications += $sentCount;
                }
            }

            if ($totalWinNotifications > 0) {
                echo "[GameTimer] 发送中奖信息推送: 台桌{$tableId}, 游戏{$gameNumber}, 中奖用户{$totalWinNotifications}个\n";
            }

            // 标记为已处理
            self::$processedWinInfo[$winKey] = time();

        } catch (\Exception $e) {
            Log::error('检查中奖信息异常', [
                'table_id' => $tableId,
                'game_status' => $gameStatus,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 获取所有活跃台桌ID
     * @return array
     */
    private static function getActiveTables()
    {
        try {
            // 获取所有有在线用户的台桌
            $onlineStats = ConnectionManager::getOnlineStats();
            $tableDetails = $onlineStats['table_details'] ?? [];
            
            // 返回有用户在线的台桌ID
            return array_keys(array_filter($tableDetails, function($count) {
                return $count > 0;
            }));

        } catch (\Exception $e) {
            Log::error('获取活跃台桌失败', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 清理过期的缓存数据
     */
    private static function cleanupExpiredCache()
    {
        try {
            $currentTime = time();
            $expireTime = 3600; // 1小时过期

            // 清理过期的结果处理记录
            foreach (self::$processedResults as $key => $timestamp) {
                if ($currentTime - $timestamp > $expireTime) {
                    unset(self::$processedResults[$key]);
                }
            }

            // 清理过期的中奖信息处理记录
            foreach (self::$processedWinInfo as $key => $timestamp) {
                if ($currentTime - $timestamp > $expireTime) {
                    unset(self::$processedWinInfo[$key]);
                }
            }

            // 清理过期的倒计时记录
            foreach (self::$lastCountdownSent as $tableId => $countdowns) {
                foreach ($countdowns as $time => $timestamp) {
                    if ($currentTime - $timestamp > 300) { // 5分钟过期
                        unset(self::$lastCountdownSent[$tableId][$time]);
                    }
                }
                
                // 如果台桌的倒计时记录为空，删除整个记录
                if (empty(self::$lastCountdownSent[$tableId])) {
                    unset(self::$lastCountdownSent[$tableId]);
                }
            }

            // 清理无效的游戏状态缓存
            $activeTables = self::getActiveTables();
            foreach (self::$lastGameStates as $tableId => $state) {
                if (!in_array($tableId, $activeTables)) {
                    unset(self::$lastGameStates[$tableId]);
                }
            }

        } catch (\Exception $e) {
            Log::error('清理过期缓存异常', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 手动触发台桌状态检查
     * @param int $tableId
     */
    public static function forceCheckTable($tableId)
    {
        try {
            echo "[GameTimer] 手动检查台桌{$tableId}状态\n";
            self::checkSingleTableStatus($tableId);
        } catch (\Exception $e) {
            echo "[ERROR] 手动检查台桌{$tableId}失败: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 获取定时器统计信息
     * @return array
     */
    public static function getStats()
    {
        try {
            return [
                'active_tables' => count(self::$lastGameStates),
                'processed_results' => count(self::$processedResults),
                'processed_win_info' => count(self::$processedWinInfo),
                'countdown_cache_size' => count(self::$lastCountdownSent),
                'memory_usage' => memory_get_usage(true),
                'update_time' => time()
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'update_time' => time()
            ];
        }
    }

    /**
     * 重置定时器缓存
     */
    public static function resetCache()
    {
        self::$lastGameStates = [];
        self::$lastCountdownSent = [];
        self::$processedResults = [];
        self::$processedWinInfo = [];
        
        echo "[GameTimer] 定时器缓存已重置\n";
    }

    /**
     * 强制发送倒计时推送（调试用）
     * @param int $tableId
     * @param int $remainingTime
     */
    public static function forceSendCountdown($tableId, $remainingTime)
    {
        try {
            $gameStatus = RedisGameManager::getGameStatus($tableId);
            if (!$gameStatus) {
                echo "[ERROR] 台桌{$tableId}没有游戏状态\n";
                return;
            }

            $totalTime = $gameStatus['total_time'] ?? 30;
            self::sendCountdownNotification($tableId, $remainingTime, $totalTime, $gameStatus);
            
        } catch (\Exception $e) {
            echo "[ERROR] 强制发送倒计时失败: " . $e->getMessage() . "\n";
        }
    }
}