<?php

declare(strict_types=1);

namespace app\job\sicbo;

use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboBetRecords;
use app\model\DianjiTable;
use app\model\User;
use think\queue\Job;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

/**
 * 骰宝通知推送任务
 * 负责处理游戏过程中的各种通知推送，包括WebSocket推送、短信、邮件等
 */
class SicboNotificationJob
{
    /**
     * 通知类型常量
     */
    private const NOTIFY_GAME_START = 'game_start';           // 游戏开始
    private const NOTIFY_BETTING_START = 'betting_start';     // 开始投注
    private const NOTIFY_BETTING_END = 'betting_end';         // 停止投注
    private const NOTIFY_GAME_RESULT = 'game_result';         // 开奖结果
    private const NOTIFY_SETTLEMENT = 'settlement';           // 结算完成
    private const NOTIFY_TABLE_STATUS = 'table_status';       // 台桌状态变更
    private const NOTIFY_SYSTEM = 'system';                   // 系统通知
    private const NOTIFY_PROMOTION = 'promotion';             // 促销活动
    private const NOTIFY_WARNING = 'warning';                 // 警告通知
    private const NOTIFY_MAINTENANCE = 'maintenance';         // 维护通知

    /**
     * 推送渠道常量
     */
    private const CHANNEL_WEBSOCKET = 'websocket';            // WebSocket推送
    private const CHANNEL_SMS = 'sms';                        // 短信推送
    private const CHANNEL_EMAIL = 'email';                    // 邮件推送
    private const CHANNEL_PUSH = 'push';                      // 移动端推送
    private const CHANNEL_POPUP = 'popup';                    // 弹窗通知

    /**
     * 通知优先级
     */
    private const PRIORITY_LOW = 1;                           // 低优先级
    private const PRIORITY_NORMAL = 2;                        // 普通优先级
    private const PRIORITY_HIGH = 3;                          // 高优先级
    private const PRIORITY_URGENT = 4;                        // 紧急优先级

    /**
     * 执行任务
     * 
     * @param Job $job 任务对象
     * @param array $data 任务数据
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        try {
            $notifyType = $data['notify_type'] ?? '';
            $tableId = $data['table_id'] ?? 0;
            $priority = $data['priority'] ?? self::PRIORITY_NORMAL;

            Log::info("开始执行骰宝通知推送任务", [
                'notify_type' => $notifyType,
                'table_id' => $tableId,
                'priority' => $priority,
                'data' => $data
            ]);

            $result = false;

            // 根据通知类型执行不同的推送逻辑
            switch ($notifyType) {
                case self::NOTIFY_GAME_START:
                    $result = $this->notifyGameStart($data);
                    break;

                case self::NOTIFY_BETTING_START:
                    $result = $this->notifyBettingStart($data);
                    break;

                case self::NOTIFY_BETTING_END:
                    $result = $this->notifyBettingEnd($data);
                    break;

                case self::NOTIFY_GAME_RESULT:
                    $result = $this->notifyGameResult($data);
                    break;

                case self::NOTIFY_SETTLEMENT:
                    $result = $this->notifySettlement($data);
                    break;

                case self::NOTIFY_TABLE_STATUS:
                    $result = $this->notifyTableStatus($data);
                    break;

                case self::NOTIFY_SYSTEM:
                    $result = $this->notifySystemMessage($data);
                    break;

                case self::NOTIFY_PROMOTION:
                    $result = $this->notifyPromotion($data);
                    break;

                case self::NOTIFY_WARNING:
                    $result = $this->notifyWarning($data);
                    break;

                case self::NOTIFY_MAINTENANCE:
                    $result = $this->notifyMaintenance($data);
                    break;

                default:
                    Log::warning("未知的通知类型: {$notifyType}");
                    $job->delete();
                    return;
            }

            if ($result) {
                Log::info("骰宝通知推送任务执行成功", [
                    'notify_type' => $notifyType,
                    'table_id' => $tableId
                ]);
                $job->delete();
            } else {
                Log::error("骰宝通知推送任务执行失败", [
                    'notify_type' => $notifyType,
                    'table_id' => $tableId
                ]);
                
                // 根据优先级决定重试策略
                $maxAttempts = $this->getMaxAttemptsByPriority($priority);
                $retryDelay = $this->getRetryDelayByPriority($priority);
                
                if ($job->attempts() < $maxAttempts) {
                    $job->release($retryDelay);
                } else {
                    $job->delete();
                    $this->handleNotificationFailure($data);
                }
            }

        } catch (\Exception $e) {
            Log::error("骰宝通知推送任务异常: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'data' => $data
            ]);
            
            $priority = $data['priority'] ?? self::PRIORITY_NORMAL;
            $maxAttempts = $this->getMaxAttemptsByPriority($priority);
            $retryDelay = $this->getRetryDelayByPriority($priority);
            
            if ($job->attempts() < $maxAttempts) {
                $job->release($retryDelay);
            } else {
                $job->delete();
                $this->handleNotificationFailure($data);
            }
        }
    }

    /**
     * 游戏开始通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifyGameStart(array $data): bool
    {
        try {
            $tableId = $data['table_id'];
            $gameNumber = $data['game_number'];
            $bettingTime = $data['betting_time'] ?? 30;

            // 获取台桌信息
            $table = DianjiTable::find($tableId);
            if (!$table) {
                return false;
            }

            // 构建通知消息
            $message = [
                'type' => self::NOTIFY_GAME_START,
                'table_id' => $tableId,
                'table_name' => $table->table_title,
                'game_number' => $gameNumber,
                'betting_time' => $bettingTime,
                'start_time' => date('Y-m-d H:i:s'),
                'message' => '新一局游戏开始，请准备投注'
            ];

            // WebSocket 台桌广播
            $this->broadcastToTable($tableId, $message);

            // 推送给关注该台桌的用户
            $this->notifyTableFollowers($tableId, $message);

            // 更新台桌状态缓存
            $this->updateTableStatusCache($tableId, 'betting', $bettingTime);

            return true;

        } catch (\Exception $e) {
            Log::error("游戏开始通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 开始投注通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifyBettingStart(array $data): bool
    {
        try {
            $tableId = $data['table_id'];
            $gameNumber = $data['game_number'];
            $countdownTime = $data['countdown_time'] ?? 30;

            $message = [
                'type' => self::NOTIFY_BETTING_START,
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'countdown_time' => $countdownTime,
                'end_time' => date('Y-m-d H:i:s', time() + $countdownTime),
                'message' => '投注开始，倒计时 ' . $countdownTime . ' 秒'
            ];

            // 广播给所有在线用户
            $this->broadcastToTable($tableId, $message);

            // 启动倒计时更新
            $this->startCountdownUpdates($tableId, $gameNumber, $countdownTime);

            return true;

        } catch (\Exception $e) {
            Log::error("开始投注通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 停止投注通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifyBettingEnd(array $data): bool
    {
        try {
            $tableId = $data['table_id'];
            $gameNumber = $data['game_number'];

            $message = [
                'type' => self::NOTIFY_BETTING_END,
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'end_time' => date('Y-m-d H:i:s'),
                'message' => '投注已截止，准备开奖'
            ];

            // 广播给台桌所有用户
            $this->broadcastToTable($tableId, $message);

            // 通知有投注的用户
            $this->notifyBettingUsers($tableId, $gameNumber, $message);

            // 更新台桌状态
            $this->updateTableStatusCache($tableId, 'drawing', 0);

            return true;

        } catch (\Exception $e) {
            Log::error("停止投注通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 开奖结果通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifyGameResult(array $data): bool
    {
        try {
            $tableId = $data['table_id'];
            $gameNumber = $data['game_number'];

            // 获取开奖结果
            $gameResult = SicboGameResults::where('game_number', $gameNumber)
                ->where('table_id', $tableId)
                ->find();

            if (!$gameResult) {
                return false;
            }

            // 构建开奖结果消息
            $message = [
                'type' => self::NOTIFY_GAME_RESULT,
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'result' => [
                    'dice1' => $gameResult->dice1,
                    'dice2' => $gameResult->dice2,
                    'dice3' => $gameResult->dice3,
                    'total_points' => $gameResult->total_points,
                    'is_big' => $gameResult->is_big,
                    'is_odd' => $gameResult->is_odd,
                    'has_triple' => $gameResult->has_triple,
                    'has_pair' => $gameResult->has_pair,
                    'winning_bets' => json_decode($gameResult->winning_bets ?? '[]', true)
                ],
                'result_time' => $gameResult->created_at,
                'message' => $this->formatResultMessage($gameResult)
            ];

            // 广播开奖结果
            $this->broadcastToTable($tableId, $message);

            // 特殊结果通知
            $this->notifySpecialResults($tableId, $gameResult, $message);

            // 更新台桌状态
            $this->updateTableStatusCache($tableId, 'settling', 0);

            return true;

        } catch (\Exception $e) {
            Log::error("开奖结果通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 结算完成通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifySettlement(array $data): bool
    {
        try {
            $tableId = $data['table_id'];
            $gameNumber = $data['game_number'];
            $settlementDetails = $data['settlement_details'] ?? [];

            // 按用户分组发送个人结算通知
            $userSettlements = $this->groupSettlementByUser($settlementDetails);

            foreach ($userSettlements as $userId => $settlement) {
                $personalMessage = [
                    'type' => self::NOTIFY_SETTLEMENT,
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'settlement' => $settlement,
                    'settle_time' => date('Y-m-d H:i:s'),
                    'message' => $this->formatSettlementMessage($settlement)
                ];

                // 发送个人通知
                $this->sendPersonalNotification($userId, $personalMessage);

                // 大奖通知
                if ($settlement['total_win_amount'] >= 10000) {
                    $this->notifyBigWin($userId, $settlement, $tableId);
                }
            }

            // 发送台桌结算汇总
            $summaryMessage = [
                'type' => 'settlement_summary',
                'table_id' => $tableId,
                'game_number' => $gameNumber,
                'total_players' => count($userSettlements),
                'total_bets' => count($settlementDetails),
                'settle_time' => date('Y-m-d H:i:s'),
                'message' => '本局结算完成'
            ];

            $this->broadcastToTable($tableId, $summaryMessage);

            // 更新台桌状态为等待中
            $this->updateTableStatusCache($tableId, 'waiting', 0);

            return true;

        } catch (\Exception $e) {
            Log::error("结算完成通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 台桌状态变更通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifyTableStatus(array $data): bool
    {
        try {
            $tableId = $data['table_id'];
            $status = $data['status'];
            $reason = $data['reason'] ?? '';

            $message = [
                'type' => self::NOTIFY_TABLE_STATUS,
                'table_id' => $tableId,
                'status' => $status,
                'reason' => $reason,
                'update_time' => date('Y-m-d H:i:s'),
                'message' => $this->formatTableStatusMessage($status, $reason)
            ];

            // 广播台桌状态变更
            $this->broadcastToTable($tableId, $message);

            // 如果台桌关闭，通知所有关注用户
            if ($status === 'closed' || $status === 'maintenance') {
                $this->notifyTableFollowers($tableId, $message, [self::CHANNEL_PUSH, self::CHANNEL_SMS]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("台桌状态通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 系统通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifySystemMessage(array $data): bool
    {
        try {
            $message = [
                'type' => self::NOTIFY_SYSTEM,
                'title' => $data['title'] ?? '系统通知',
                'content' => $data['content'] ?? '',
                'level' => $data['level'] ?? 'info',
                'send_time' => date('Y-m-d H:i:s'),
                'expire_time' => $data['expire_time'] ?? null
            ];

            $targetUsers = $data['target_users'] ?? null;
            $channels = $data['channels'] ?? [self::CHANNEL_WEBSOCKET];

            if ($targetUsers) {
                // 发送给指定用户
                foreach ($targetUsers as $userId) {
                    $this->sendMultiChannelNotification($userId, $message, $channels);
                }
            } else {
                // 全平台广播
                $this->broadcastSystemMessage($message, $channels);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("系统通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 促销活动通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifyPromotion(array $data): bool
    {
        try {
            $message = [
                'type' => self::NOTIFY_PROMOTION,
                'title' => $data['title'] ?? '优惠活动',
                'content' => $data['content'] ?? '',
                'promotion_id' => $data['promotion_id'] ?? 0,
                'start_time' => $data['start_time'] ?? date('Y-m-d H:i:s'),
                'end_time' => $data['end_time'] ?? null,
                'link' => $data['link'] ?? null
            ];

            $targetUsers = $data['target_users'] ?? null;
            $userGroups = $data['user_groups'] ?? null;

            if ($targetUsers) {
                foreach ($targetUsers as $userId) {
                    $this->sendPersonalNotification($userId, $message);
                }
            } elseif ($userGroups) {
                $this->notifyUserGroups($userGroups, $message);
            } else {
                $this->broadcastSystemMessage($message, [self::CHANNEL_WEBSOCKET, self::CHANNEL_PUSH]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("促销活动通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 警告通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifyWarning(array $data): bool
    {
        try {
            $message = [
                'type' => self::NOTIFY_WARNING,
                'title' => $data['title'] ?? '警告',
                'content' => $data['content'] ?? '',
                'level' => 'warning',
                'send_time' => date('Y-m-d H:i:s'),
                'require_confirm' => $data['require_confirm'] ?? true
            ];

            $userId = $data['user_id'] ?? null;
            $tableId = $data['table_id'] ?? null;

            if ($userId) {
                // 发送给特定用户
                $this->sendMultiChannelNotification($userId, $message, [
                    self::CHANNEL_WEBSOCKET,
                    self::CHANNEL_POPUP,
                    self::CHANNEL_SMS
                ]);
            } elseif ($tableId) {
                // 发送给台桌所有用户
                $this->broadcastToTable($tableId, $message);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("警告通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 维护通知
     * 
     * @param array $data 通知数据
     * @return bool
     */
    private function notifyMaintenance(array $data): bool
    {
        try {
            $message = [
                'type' => self::NOTIFY_MAINTENANCE,
                'title' => '系统维护通知',
                'content' => $data['content'] ?? '',
                'start_time' => $data['start_time'] ?? date('Y-m-d H:i:s'),
                'end_time' => $data['end_time'] ?? null,
                'affected_tables' => $data['affected_tables'] ?? null
            ];

            // 全平台广播维护通知
            $this->broadcastSystemMessage($message, [
                self::CHANNEL_WEBSOCKET,
                self::CHANNEL_PUSH,
                self::CHANNEL_EMAIL,
                self::CHANNEL_SMS
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("维护通知失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * WebSocket 台桌广播
     * 
     * @param int $tableId 台桌ID
     * @param array $message 消息内容
     * @return void
     */
    private function broadcastToTable(int $tableId, array $message): void
    {
        try {
            // 使用 worker_tcp 发送台桌广播
            worker_tcp(
                0, // 0表示广播
                $message['message'] ?? '通知',
                array_merge($message, [
                    'broadcast_type' => 'table',
                    'target_table' => $tableId
                ]),
                200
            );

        } catch (\Exception $e) {
            Log::error("台桌广播失败: " . $e->getMessage());
        }
    }

    /**
     * 发送个人通知
     * 
     * @param int $userId 用户ID
     * @param array $message 消息内容
     * @return void
     */
    private function sendPersonalNotification(int $userId, array $message): void
    {
        try {
            worker_tcp(
                $userId,
                $message['message'] ?? '通知',
                $message,
                200
            );

        } catch (\Exception $e) {
            Log::error("个人通知发送失败: " . $e->getMessage());
        }
    }

    /**
     * 多渠道通知发送
     * 
     * @param int $userId 用户ID
     * @param array $message 消息内容
     * @param array $channels 推送渠道
     * @return void
     */
    private function sendMultiChannelNotification(int $userId, array $message, array $channels): void
    {
        foreach ($channels as $channel) {
            switch ($channel) {
                case self::CHANNEL_WEBSOCKET:
                    $this->sendPersonalNotification($userId, $message);
                    break;

                case self::CHANNEL_SMS:
                    $this->sendSmsNotification($userId, $message);
                    break;

                case self::CHANNEL_EMAIL:
                    $this->sendEmailNotification($userId, $message);
                    break;

                case self::CHANNEL_PUSH:
                    $this->sendPushNotification($userId, $message);
                    break;

                case self::CHANNEL_POPUP:
                    $this->sendPopupNotification($userId, $message);
                    break;
            }
        }
    }

    /**
     * 短信通知
     * 
     * @param int $userId 用户ID
     * @param array $message 消息内容
     * @return void
     */
    private function sendSmsNotification(int $userId, array $message): void
    {
        try {
            $user = User::find($userId);
            if (!$user || empty($user->mobile)) {
                return;
            }

            // 这里集成短信服务商API
            // 例如：阿里云短信、腾讯云短信等
            $smsContent = $this->formatSmsContent($message);
            
            Log::info("发送短信通知", [
                'user_id' => $userId,
                'mobile' => $user->mobile,
                'content' => $smsContent
            ]);

            // 实际短信发送代码
            // $smsService->send($user->mobile, $smsContent);

        } catch (\Exception $e) {
            Log::error("短信通知发送失败: " . $e->getMessage());
        }
    }

    /**
     * 邮件通知
     * 
     * @param int $userId 用户ID
     * @param array $message 消息内容
     * @return void
     */
    private function sendEmailNotification(int $userId, array $message): void
    {
        try {
            $user = User::find($userId);
            if (!$user || empty($user->email)) {
                return;
            }

            // 邮件发送逻辑
            $emailContent = $this->formatEmailContent($message);
            
            Log::info("发送邮件通知", [
                'user_id' => $userId,
                'email' => $user->email,
                'subject' => $message['title'] ?? '骰宝通知'
            ]);

            // 实际邮件发送代码
            // $emailService->send($user->email, $emailContent);

        } catch (\Exception $e) {
            Log::error("邮件通知发送失败: " . $e->getMessage());
        }
    }

    /**
     * 移动端推送通知
     * 
     * @param int $userId 用户ID
     * @param array $message 消息内容
     * @return void
     */
    private function sendPushNotification(int $userId, array $message): void
    {
        try {
            // 获取用户的推送token
            $deviceTokens = $this->getUserDeviceTokens($userId);
            
            if (empty($deviceTokens)) {
                return;
            }

            $pushData = [
                'title' => $message['title'] ?? '骰宝通知',
                'body' => $message['content'] ?? $message['message'] ?? '',
                'data' => $message
            ];

            foreach ($deviceTokens as $token) {
                Log::info("发送推送通知", [
                    'user_id' => $userId,
                    'device_token' => $token,
                    'push_data' => $pushData
                ]);

                // 实际推送发送代码
                // $pushService->send($token, $pushData);
            }

        } catch (\Exception $e) {
            Log::error("推送通知发送失败: " . $e->getMessage());
        }
    }

    /**
     * 弹窗通知
     * 
     * @param int $userId 用户ID
     * @param array $message 消息内容
     * @return void
     */
    private function sendPopupNotification(int $userId, array $message): void
    {
        try {
            $popupMessage = array_merge($message, [
                'display_type' => 'popup',
                'auto_close' => $message['auto_close'] ?? 5000, // 5秒后自动关闭
                'require_confirm' => $message['require_confirm'] ?? false
            ]);

            $this->sendPersonalNotification($userId, $popupMessage);

        } catch (\Exception $e) {
            Log::error("弹窗通知发送失败: " . $e->getMessage());
        }
    }

    /**
     * 启动倒计时更新
     * 
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @param int $countdownTime 倒计时时间
     * @return void
     */
    private function startCountdownUpdates(int $tableId, string $gameNumber, int $countdownTime): void
    {
        try {
            $intervals = [30, 20, 10, 5, 3, 2, 1]; // 需要推送更新的时间点
            
            foreach ($intervals as $seconds) {
                if ($seconds < $countdownTime) {
                    $delay = $countdownTime - $seconds;
                    
                    // 推送倒计时更新任务
                    $jobData = [
                        'notify_type' => 'countdown_update',
                        'table_id' => $tableId,
                        'game_number' => $gameNumber,
                        'remaining_time' => $seconds,
                        'priority' => self::PRIORITY_HIGH
                    ];

                    // Queue::later($delay, self::class, $jobData, 'notifications');
                }
            }

        } catch (\Exception $e) {
            Log::error("启动倒计时更新失败: " . $e->getMessage());
        }
    }

    /**
     * 通知台桌关注者
     * 
     * @param int $tableId 台桌ID
     * @param array $message 消息内容
     * @param array $channels 推送渠道
     * @return void
     */
    private function notifyTableFollowers(int $tableId, array $message, array $channels = [self::CHANNEL_WEBSOCKET]): void
    {
        try {
            // 获取关注该台桌的用户列表
            $followers = $this->getTableFollowers($tableId);
            
            foreach ($followers as $userId) {
                $this->sendMultiChannelNotification($userId, $message, $channels);
            }

        } catch (\Exception $e) {
            Log::error("通知台桌关注者失败: " . $e->getMessage());
        }
    }

    /**
     * 通知投注用户
     * 
     * @param int $tableId 台桌ID
     * @param string $gameNumber 游戏局号
     * @param array $message 消息内容
     * @return void
     */
    private function notifyBettingUsers(int $tableId, string $gameNumber, array $message): void
    {
        try {
            $bettingUsers = SicboBetRecords::where('table_id', $tableId)
                ->where('game_number', $gameNumber)
                ->group('user_id')
                ->column('user_id');

            foreach ($bettingUsers as $userId) {
                $this->sendPersonalNotification($userId, $message);
            }

        } catch (\Exception $e) {
            Log::error("通知投注用户失败: " . $e->getMessage());
        }
    }

    /**
     * 特殊结果通知
     * 
     * @param int $tableId 台桌ID
     * @param SicboGameResults $gameResult 游戏结果
     * @param array $baseMessage 基础消息
     * @return void
     */
    private function notifySpecialResults(int $tableId, SicboGameResults $gameResult, array $baseMessage): void
    {
        try {
            $specialMessages = [];

            // 三同号通知
            if ($gameResult->has_triple) {
                $specialMessages[] = [
                    'type' => 'special_result',
                    'special_type' => 'triple',
                    'message' => "恭喜！开出三同号 {$gameResult->triple_number}！"
                ];
            }

            // 连续相同结果通知
            $this->checkConsecutiveResults($tableId, $gameResult, $specialMessages);

            // 发送特殊结果通知
            foreach ($specialMessages as $specialMsg) {
                $message = array_merge($baseMessage, $specialMsg);
                $this->broadcastToTable($tableId, $message);
            }

        } catch (\Exception $e) {
            Log::error("特殊结果通知失败: " . $e->getMessage());
        }
    }

    /**
     * 大奖通知
     * 
     * @param int $userId 用户ID
     * @param array $settlement 结算数据
     * @param int $tableId 台桌ID
     * @return void
     */
    private function notifyBigWin(int $userId, array $settlement, int $tableId): void
    {
        try {
            $winAmount = $settlement['total_win_amount'];
            
            $message = [
                'type' => 'big_win',
                'user_id' => $userId,
                'win_amount' => $winAmount,
                'table_id' => $tableId,
                'message' => "恭喜玩家获得大奖 ¥{$winAmount}！"
            ];

            // 个人通知
            $this->sendMultiChannelNotification($userId, $message, [
                self::CHANNEL_WEBSOCKET,
                self::CHANNEL_POPUP,
                self::CHANNEL_PUSH
            ]);

            // 台桌广播（隐藏用户信息）
            $broadcastMessage = array_merge($message, [
                'user_id' => null,
                'message' => "恭喜有玩家获得大奖 ¥{$winAmount}！"
            ]);
            
            $this->broadcastToTable($tableId, $broadcastMessage);

        } catch (\Exception $e) {
            Log::error("大奖通知失败: " . $e->getMessage());
        }
    }

    /**
     * 系统广播
     * 
     * @param array $message 消息内容
     * @param array $channels 推送渠道
     * @return void
     */
    private function broadcastSystemMessage(array $message, array $channels): void
    {
        try {
            // WebSocket 全平台广播
            if (in_array(self::CHANNEL_WEBSOCKET, $channels)) {
                worker_tcp(
                    0, // 0表示全平台广播
                    $message['title'] ?? '系统通知',
                    array_merge($message, ['broadcast_type' => 'system']),
                    200
                );
            }

            // 其他渠道的广播逻辑
            // ...

        } catch (\Exception $e) {
            Log::error("系统广播失败: " . $e->getMessage());
        }
    }

    /**
     * 根据优先级获取最大重试次数
     * 
     * @param int $priority 优先级
     * @return int
     */
    private function getMaxAttemptsByPriority(int $priority): int
    {
        switch ($priority) {
            case self::PRIORITY_URGENT:
                return 5;
            case self::PRIORITY_HIGH:
                return 3;
            case self::PRIORITY_NORMAL:
                return 2;
            case self::PRIORITY_LOW:
                return 1;
            default:
                return 2;
        }
    }

    /**
     * 根据优先级获取重试延迟
     * 
     * @param int $priority 优先级
     * @return int
     */
    private function getRetryDelayByPriority(int $priority): int
    {
        switch ($priority) {
            case self::PRIORITY_URGENT:
                return 10;  // 10秒
            case self::PRIORITY_HIGH:
                return 30;  // 30秒
            case self::PRIORITY_NORMAL:
                return 60;  // 1分钟
            case self::PRIORITY_LOW:
                return 300; // 5分钟
            default:
                return 60;
        }
    }

    /**
     * 处理通知失败
     * 
     * @param array $data 任务数据
     * @return void
     */
    private function handleNotificationFailure(array $data): void
    {
        Log::error("通知推送最终失败", $data);
        
        // 可以在这里实现失败补偿机制
        // 例如：记录到失败队列，稍后重试
        // 或者：降级到其他通知渠道
    }

    /**
     * 更新台桌状态缓存
     * 
     * @param int $tableId 台桌ID
     * @param string $status 状态
     * @param int $countdown 倒计时
     * @return void
     */
    private function updateTableStatusCache(int $tableId, string $status, int $countdown): void
    {
        try {
            $statusData = [
                'status' => $status,
                'countdown' => $countdown,
                'update_time' => time()
            ];

            Cache::set("table_status_{$tableId}", $statusData, 3600);

        } catch (\Exception $e) {
            Log::error("更新台桌状态缓存失败: " . $e->getMessage());
        }
    }

    /**
     * 格式化开奖结果消息
     * 
     * @param SicboGameResults $result 游戏结果
     * @return string
     */
    private function formatResultMessage(SicboGameResults $result): string
    {
        $dice = "{$result->dice1}-{$result->dice2}-{$result->dice3}";
        $bigSmall = $result->is_big ? '大' : '小';
        $oddEven = $result->is_odd ? '单' : '双';
        
        $message = "开奖结果：{$dice}，总点数：{$result->total_points}，{$bigSmall}/{$oddEven}";
        
        if ($result->has_triple) {
            $message .= "，三同号 {$result->triple_number}";
        } elseif ($result->has_pair) {
            $message .= "，包含对子";
        }
        
        return $message;
    }

    /**
     * 格式化结算消息
     * 
     * @param array $settlement 结算数据
     * @return string
     */
    private function formatSettlementMessage(array $settlement): string
    {
        $winAmount = $settlement['total_win_amount'];
        $betAmount = $settlement['total_bet_amount'];
        $winCount = $settlement['win_count'];
        $betCount = $settlement['bet_count'];
        
        if ($winAmount > 0) {
            $profit = $winAmount - $betAmount;
            $profitText = $profit > 0 ? "盈利 ¥{$profit}" : "亏损 ¥" . abs($profit);
            return "结算完成：投注 {$betCount} 笔，中奖 {$winCount} 笔，{$profitText}";
        } else {
            return "结算完成：投注 {$betCount} 笔，未中奖";
        }
    }

    /**
     * 格式化台桌状态消息
     * 
     * @param string $status 状态
     * @param string $reason 原因
     * @return string
     */
    private function formatTableStatusMessage(string $status, string $reason): string
    {
        $statusTexts = [
            'open' => '台桌已开放',
            'closed' => '台桌已关闭',
            'maintenance' => '台桌维护中',
            'betting' => '正在投注',
            'drawing' => '正在开奖',
            'settling' => '正在结算'
        ];
        
        $message = $statusTexts[$status] ?? "台桌状态：{$status}";
        
        if (!empty($reason)) {
            $message .= "，原因：{$reason}";
        }
        
        return $message;
    }

    /**
     * 按用户分组结算数据
     * 
     * @param array $settlementDetails 结算详情
     * @return array
     */
    private function groupSettlementByUser(array $settlementDetails): array
    {
        $userSettlements = [];
        
        foreach ($settlementDetails as $detail) {
            $userId = $detail['user_id'];
            
            if (!isset($userSettlements[$userId])) {
                $userSettlements[$userId] = [
                    'user_id' => $userId,
                    'total_bet_amount' => 0,
                    'total_win_amount' => 0,
                    'win_count' => 0,
                    'bet_count' => 0,
                    'bets' => []
                ];
            }
            
            $userSettlements[$userId]['total_bet_amount'] += $detail['bet_amount'];
            $userSettlements[$userId]['total_win_amount'] += $detail['win_amount'];
            $userSettlements[$userId]['bet_count']++;
            
            if ($detail['is_win']) {
                $userSettlements[$userId]['win_count']++;
            }
            
            $userSettlements[$userId]['bets'][] = $detail;
        }
        
        return $userSettlements;
    }

    /**
     * 获取台桌关注者
     * 
     * @param int $tableId 台桌ID
     * @return array
     */
    private function getTableFollowers(int $tableId): array
    {
        // 这里应该从数据库或缓存获取关注该台桌的用户
        // 暂时返回空数组
        return [];
    }

    /**
     * 获取用户设备Token
     * 
     * @param int $userId 用户ID
     * @return array
     */
    private function getUserDeviceTokens(int $userId): array
    {
        // 这里应该从数据库获取用户的设备推送token
        // 暂时返回空数组
        return [];
    }

    /**
     * 检查连续结果
     * 
     * @param int $tableId 台桌ID
     * @param SicboGameResults $currentResult 当前结果
     * @param array &$specialMessages 特殊消息数组
     * @return void
     */
    private function checkConsecutiveResults(int $tableId, SicboGameResults $currentResult, array &$specialMessages): void
    {
        try {
            // 获取最近10局结果
            $recentResults = SicboGameResults::where('table_id', $tableId)
                ->where('status', 1)
                ->order('created_at desc')
                ->limit(10)
                ->column('is_big,is_odd');

            if (count($recentResults) >= 5) {
                // 检查连续大小
                $consecutiveBig = 0;
                $consecutiveSmall = 0;
                
                foreach ($recentResults as $result) {
                    if ($result['is_big']) {
                        $consecutiveBig++;
                        $consecutiveSmall = 0;
                    } else {
                        $consecutiveSmall++;
                        $consecutiveBig = 0;
                    }
                }
                
                if ($consecutiveBig >= 5) {
                    $specialMessages[] = [
                        'type' => 'consecutive_result',
                        'consecutive_type' => 'big',
                        'count' => $consecutiveBig,
                        'message' => "连续 {$consecutiveBig} 局开大！"
                    ];
                }
                
                if ($consecutiveSmall >= 5) {
                    $specialMessages[] = [
                        'type' => 'consecutive_result',
                        'consecutive_type' => 'small',
                        'count' => $consecutiveSmall,
                        'message' => "连续 {$consecutiveSmall} 局开小！"
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error("检查连续结果失败: " . $e->getMessage());
        }
    }

    /**
     * 格式化短信内容
     * 
     * @param array $message 消息内容
     * @return string
     */
    private function formatSmsContent(array $message): string
    {
        return $message['content'] ?? $message['message'] ?? '';
    }

    /**
     * 格式化邮件内容
     * 
     * @param array $message 消息内容
     * @return string
     */
    private function formatEmailContent(array $message): string
    {
        // 这里可以返回HTML格式的邮件内容
        return $message['content'] ?? $message['message'] ?? '';
    }

    /**
     * 通知用户组
     * 
     * @param array $userGroups 用户组
     * @param array $message 消息内容
     * @return void
     */
    private function notifyUserGroups(array $userGroups, array $message): void
    {
        // 根据用户组获取用户列表并发送通知
        // 实现用户分组通知逻辑
    }

    /**
     * 静态方法：推送游戏通知
     * 
     * @param string $notifyType 通知类型
     * @param array $data 通知数据
     * @param int $priority 优先级
     * @return bool
     */
    public static function pushNotification(string $notifyType, array $data, int $priority = self::PRIORITY_NORMAL): bool
    {
        try {
            $jobData = array_merge($data, [
                'notify_type' => $notifyType,
                'priority' => $priority,
                'timestamp' => time()
            ]);

            // Queue::push(self::class, $jobData, 'notifications');
            
            Log::info("推送骰宝通知任务", [
                'notify_type' => $notifyType,
                'priority' => $priority
            ]);
            
            return true;

        } catch (\Exception $e) {
            Log::error("推送通知任务失败: " . $e->getMessage());
            return false;
        }
    }
}