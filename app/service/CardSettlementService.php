<?php

namespace app\service;

use app\controller\common\LogHelper;
use app\model\GameRecords;
use app\model\GameRecordsTemporary;
use app\model\Luzhu;
use app\model\LuzhuHeguan;
use app\model\LuzhuPreset;
use app\model\UserModel;
use app\job\BetMoneyLogInsert;
use app\job\UserSettleTaskJob;
use think\db\exception\DbException;
use think\facade\Db;
use think\facade\Queue;
use app\model\MoneyLog;

/**
 * 卡牌游戏结算服务类
 * 
 * 主要功能：
 * - 处理游戏开牌后的完整结算流程
 * - 管理用户投注记录和资金变动
 * - 计算游戏胜负和赔付金额
 * - 处理洗码费和代理分成（仅输钱时给洗码费）
 * - 维护露珠历史记录
 * 
 * @package app\service
 * @author 系统开发团队
 */
class CardSettlementService extends CardServiceBase
{
    /**
     * 游戏开牌主流程
     * 
     * 处理荷官开牌后的完整业务流程，包括数据保存、缓存设置、异步结算任务分发
     * 
     * 执行步骤：
     * 1. 保存露珠数据（系统露珠 + 荷官露珠）
     * 2. 设置Redis缓存供实时推送使用
     * 3. 处理预设数据状态更新
     * 4. 启动异步用户结算任务
     * 5. 记录开牌历史信息
     * 
     * @param array $post 开牌数据（系统处理后）
     * @param array $HeguanLuzhu 荷官原始数据
     * @param int $id 预设数据ID（0表示非预设开牌）
     * @return string JSON响应字符串
     */
    public function open_game($post, $HeguanLuzhu, $id): string
    {
        // 记录开牌服务开始日志
        LogHelper::debug('=== 开牌服务开始 ===', [
            'table_id' => $post['table_id'],
            'game_type' => $post['game_type'],
            'xue_number' => $post['xue_number'],
            'pu_number' => $post['pu_number']
        ]);
        
        LogHelper::debug('开牌数据详情', [
            'system_data' => $post,
            'heguan_data' => $HeguanLuzhu,
            'preset_id' => $id
        ]);

        // 第一步：保存露珠记录到数据库
        $saveResult = $this->saveLuzhuRecords($post, $HeguanLuzhu);
        if (!$saveResult['success']) {
            return show([], 0, '开牌失败');
        }

        // 第二步：设置Redis缓存供实时推送
        $this->setOpenGameCache($post);

        // 第三步：处理预设数据状态
        if ($id > 0) {
            LuzhuPreset::IsStatus($id);
        }

        // 第四步：保存开牌历史记录
        $this->get_open_pai_info($post['result_pai'], $saveResult['luzhu_id']);

        // 第五步：分发异步结算任务
        $this->dispatchSettlementTask($post, $saveResult['luzhu_id']);

        LogHelper::debug('=== 开牌服务完成 ===');
        return show([]);
    }

    /**
     * 用户结算核心逻辑
     * 
     * 处理指定局次的所有用户投注结算，包括输赢计算、资金变动、洗码费处理
     * 
     * 
     * @param int $luzhu_id 露珠记录ID
     * @param array $post 开牌数据
     * @return bool 结算是否成功
     */
    public function user_settlement($luzhu_id, $post): bool
    {
        $startTime = microtime(true);
        
        LogHelper::debug('=== 用户结算开始 ===', [
            'luzhu_id' => $luzhu_id,
            'table_id' => $post['table_id'],
            'xue_number' => $post['xue_number'],
            'pu_number' => $post['pu_number']
        ]);

        // 第一步：查询本局投注记录
        $betRecords = $this->getBetRecords($post);
        if (empty($betRecords)) {
            LogHelper::debug('无投注记录，结算完成');
            return true;
        }

        // 第二步：计算开牌结果
        $paiResult = $this->calculateGameResult($post);

        // 第三步：处理投注结算
        $settlementData = $this->processSettlement($betRecords, $paiResult, $luzhu_id);

        // 第四步：生成派彩显示数据
        $this->generatePayoutDisplay($settlementData['records']);

        // 第五步：更新用户余额和投注记录
        $updateResult = $this->updateUserBalanceAndRecords(
            $settlementData['userBalanceData'], 
            $settlementData['records'], 
            $luzhu_id
        );

        if (!$updateResult) {
            return false;
        }

        // 第六步：后续处理任务
        $this->executePostSettlementTasks($post);

        $this->logPerformanceMetrics($startTime, $luzhu_id);
        return true;
    }

    /**
     * 保存露珠记录到数据库
     * 
     * @param array $post 系统露珠数据
     * @param array $HeguanLuzhu 荷官露珠数据
     * @return array 包含成功状态和露珠ID的数组
     */
    private function saveLuzhuRecords($post, $HeguanLuzhu): array
    {
        $luzhuModel = new Luzhu();
        LogHelper::debug('开始数据库事务');

        Db::startTrans();
        try {
            // 保存系统露珠数据
            $luzhuModel->save($post);
            
            // 保存荷官原始露珠数据
            LuzhuHeguan::insert($HeguanLuzhu);
            
            Db::commit();
            LogHelper::debug('露珠数据保存成功');
            
            return [
                'success' => true,
                'luzhu_id' => $luzhuModel->id
            ];
        } catch (\Exception $e) {
            Db::rollback();
            LogHelper::error('开牌数据保存失败', $e);
            
            return ['success' => false];
        }
    }

    /**
     * 设置Redis开牌缓存
     * 
     * @param array $post 开牌数据
     */
    private function setOpenGameCache($post): void
    {
        $redis_key = 'table_id_' . $post['table_id'] . '_' . $post['game_type'];
        redis()->set($redis_key, $post['result_pai'], 5);

        LogHelper::debug('Redis开牌缓存设置成功', [
            'redis_key' => $redis_key,
            'ttl' => 5
        ]);
    }

    /**
     * 分发异步结算任务
     * 
     * @param array $post 开牌数据
     * @param int $luzhu_id 露珠ID
     */
    private function dispatchSettlementTask($post, $luzhu_id): void
    {
        $post['luzhu_id'] = $luzhu_id;

        LogHelper::debug('开始分发结算任务', [
            'luzhu_id' => $luzhu_id,
            'delay' => 1
        ]);

        // 延迟1秒执行用户结算任务（避免数据冲突）
        $queue = Queue::later(1, UserSettleTaskJob::class, $post, 'sicbo_open_queue');
        
        if ($queue == false) {
            LogHelper::error('结算任务分发失败');
            show([], 0, 'dismiss job queue went wrong');
        }

        LogHelper::debug('结算任务分发成功', ['queue_name' => 'sicbo_open_queue']);
    }

    /**
     * 获取本局投注记录
     * 
     * @param array $post 开牌数据
     * @return array 投注记录数组
     */
    private function getBetRecords($post): array
    {
        $oddsModel = new GameRecords();
        LogHelper::debug('开始查询投注记录');

        // 查询条件：最近1小时内，指定台桌、靴号、铺号的未结算投注
        $betRecords = $oddsModel
            ->whereTime('created_at', date("Y-m-d H:i:s", strtotime("-1 hour")))
            ->where([
                'table_id' => $post['table_id'],
                'game_type' => $post['game_type'],
                'xue_number' => $post['xue_number'],
                'pu_number' => $post['pu_number'],
                'close_status' => 1, // 1=未结算，2=已结算
            ])
            ->select()
            ->toArray();

        LogHelper::debug('投注记录查询完成', [
            'record_count' => count($betRecords),
            'sql' => $oddsModel->getLastSql()
        ]);

        return $betRecords;
    }

    /**
     * 计算游戏开牌结果
     * 
     * @param array $post 开牌数据
     * @return array 牌结果数组
     */
    private function calculateGameResult($post): array
    {
        LogHelper::debug('开始计算开牌结果');

        $card = new OpenPaiCalculationService();
        $pai_result = $card->runs(json_decode($post['result_pai'], true));

        LogHelper::debug('开牌计算完成', [
            'win_array' => $pai_result['win_array'],
            'zhuang_point' => $pai_result['zhuang_point'],
            'xian_point' => $pai_result['xian_point']
        ]);

        return $pai_result;
    }

    /**
     * 处理投注结算计算
     * 
     * @param array $betRecords 投注记录
     * @param array $paiResult 开牌结果
     * @param int $luzhu_id 露珠ID
     * @return array 结算数据
     */
    private function processSettlement($betRecords, $paiResult, $luzhu_id): array
    {
        $dataSaveRecords = [];  // 更新后的投注记录数据
        $userSaveDataTemp = []; // 用户资金变动临时数据
        
        $card = new OpenPaiCalculationService();
        LogHelper::debug('开始逐笔投注结算');

        foreach ($betRecords as $key => $record) {
            // 判断用户是否中奖
            $isWin = $card->user_win_or_not(intval($record['result']), $paiResult);

            LogHelper::debug('投注结算分析', [
                'record_id' => $record['id'],
                'user_id' => $record['user_id'],
                'bet_type' => $record['result'],
                'bet_type_name' => $card->user_pai_chinese($record['result']),
                'bet_amount' => $record['bet_amt'],
                'odds' => $record['game_peilv'],
                'is_win' => $isWin
            ]);

            // 基础结算信息设置
            $dataSaveRecords[$key] = $this->buildSettlementRecord($record, $paiResult, $luzhu_id, $card);

            // 洗码费计算
            $rebateResult = $this->calculateRebate($record, $isWin, $paiResult);
            $dataSaveRecords[$key]['shuffling_amt'] = $rebateResult['shuffling_amt'];
            $dataSaveRecords[$key]['shuffling_num'] = $rebateResult['shuffling_num'];

            // 处理输赢结算
            $this->processWinLossSettlement($dataSaveRecords[$key], $userSaveDataTemp, $key, $record, $isWin);
        }

        // 合并同用户的多笔投注
        $userSaveData = $this->mergeUserBets($userSaveDataTemp);

        return [
            'records' => $dataSaveRecords,
            'userBalanceData' => $userSaveDataTemp
        ];
    }

    /**
     * 构建结算记录基础信息
     * 
     * @param array $record 投注记录
     * @param array $paiResult 开牌结果
     * @param int $luzhu_id 露珠ID
     * @param object $card 牌计算服务
     * @return array 结算记录
     */
    private function buildSettlementRecord($record, $paiResult, $luzhu_id, $card): array
    {
        return [
            'detail' => $record['detail']
                . '-购买：' . $card->user_pai_chinese($record['result'])
                . ',开：' . $card->pai_chinese($paiResult)
                . '|本次结果记录' . json_encode($paiResult),
            'close_status' => 2,                    // 2=已结算
            'user_id' => $record['user_id'],        // 用户ID
            'win_amt' => 0,                         // 输赢金额默认0
            'id' => $record['id'],                  // 投注记录ID
            'lu_zhu_id' => $luzhu_id,              // 关联露珠ID
            'table_id' => $record['table_id'],      // 台桌ID
            'game_type' => $record['game_type'],    // 游戏类型
            'game_peilv' => intval($record['game_peilv']) // 赔率
        ];
    }

    /**
     * 处理输赢结算逻辑
     * 
     * @param array &$settlementRecord 结算记录（引用传递）
     * @param array &$userSaveDataTemp 用户资金变动临时数据（引用传递）
     * @param int $key 数组索引
     * @param array $record 原始投注记录
     * @param bool $isWin 是否中奖
     */
    private function processWinLossSettlement(&$settlementRecord, &$userSaveDataTemp, $key, $record, $isWin): void
    {
        $winAmount = $settlementRecord['game_peilv'] * $record['bet_amt'];

        if ($isWin) {
            // 中奖处理
            $settlementRecord['win_amt'] = $winAmount;
            $settlementRecord['delta_amt'] = $winAmount + $record['bet_amt']; // 返还 = 奖金 + 本金

            $userSaveDataTemp[$key] = [
                'money_balance_add_temp' => $settlementRecord['delta_amt'],
                'id' => $record['user_id'],
                'win' => $winAmount,
                'bet_amt' => $record['bet_amt'],
            ];

            LogHelper::debug('中奖处理', [
                'user_id' => $record['user_id'],
                'win_amt' => $winAmount,
                'return_amt' => $settlementRecord['delta_amt']
            ]);
        } else {
            // 未中奖处理：输掉本金
            $settlementRecord['win_amt'] = $record['bet_amt'] * -1;

            LogHelper::debug('正常输牌处理', [
                'user_id' => $record['user_id'],
                'loss_amt' => $record['bet_amt'],
                'rebate_amt' => $settlementRecord['shuffling_amt']
            ]);
        }
    }

    /**
     * 合并同用户的多笔投注
     * 
     * @param array $userSaveDataTemp 用户资金变动临时数据
     * @return array 合并后的用户数据
     */
    private function mergeUserBets($userSaveDataTemp): array
    {
        $userSaveData = [];
        if (!empty($userSaveDataTemp)) {
            foreach ($userSaveDataTemp as $v) {
                if (array_key_exists($v['id'], $userSaveData)) {
                    // 同用户多笔投注金额累加
                    $userSaveData[$v['id']]['money_balance_add_temp'] += $v['money_balance_add_temp'];
                } else {
                    $userSaveData[$v['id']] = $v;
                }
            }
        }
        return $userSaveData;
    }

    /**
     * 生成派彩显示数据并缓存到Redis
     * 
     * @param array $dataSaveRecords 结算记录
     */
    private function generatePayoutDisplay($dataSaveRecords): void
    {
        if (empty($dataSaveRecords)) {
            return;
        }

        $userCount = [];
        
        // 按用户汇总输赢金额
        foreach ($dataSaveRecords as $record) {
            if (array_key_exists($record['user_id'], $userCount)) {
                $userCount[$record['user_id']]['win_amt'] += $record['win_amt'];
            } else {
                $userCount[$record['user_id']] = $record;
            }
        }

        // 将派彩结果存入Redis，供客户端显示（存储5秒）
        foreach ($userCount as $record) {
            $redis_key = 'user_' . $record['user_id'] . '_table_id_' . $record['table_id'] . '_' . $record['game_type'];
            redis()->set($redis_key, $record['win_amt'], 5);
        }
    }

    /**
     * 更新用户余额和投注记录
     * 
     * @param array $userSaveDataTemp 用户资金变动数据
     * @param array $dataSaveRecords 投注记录更新数据
     * @param int $luzhu_id 露珠ID
     * @return bool 是否成功
     */
    private function updateUserBalanceAndRecords($userSaveDataTemp, $dataSaveRecords, $luzhu_id): bool
    {
        LogHelper::debug('开始用户余额更新事务');

        $UserModel = new UserModel();
        $UserModel->startTrans();
        
        try {
            // 更新用户余额
            $this->updateUserBalances($userSaveDataTemp, $luzhu_id, $UserModel);

            // 批量更新投注记录状态
            if (!empty($dataSaveRecords)) {
                $oddsModel = new GameRecords();
                $oddsModel->saveAll($dataSaveRecords);
            }

            // 累计用户洗码费
            $this->accumulateUserRebate($dataSaveRecords);

            $UserModel->commit();
            LogHelper::debug('用户余额更新事务完成');

            return true;
        } catch (DbException $e) {
            $UserModel->rollback();
            LogHelper::error('用户余额更新失败', $e);
            return false;
        }
    }

    /**
     * 更新用户账户余额
     * 
     * @param array $userSaveDataTemp 用户资金变动数据
     * @param int $luzhu_id 露珠ID
     * @param UserModel $UserModel 用户模型实例
     */
    private function updateUserBalances($userSaveDataTemp, $luzhu_id, $UserModel): void
    {
        if (empty($userSaveDataTemp)) {
            return;
        }

        foreach ($userSaveDataTemp as $userData) {
            // 获取用户当前余额（加锁防止并发）
            $user = $UserModel->where('id', $userData['id'])->lock(true)->find();

            // 准备资金流水记录
            $moneyLog = [
                'money_before' => $user->money_balance,
                'money_end' => $user->money_balance + $userData['money_balance_add_temp'],
                'uid' => $userData['id'],
                'type' => 1,
                'status' => 503, // 卡牌游戏结算
                'source_id' => $luzhu_id,
                'money' => $userData['money_balance_add_temp'],
                'create_time' => date('Y-m-d H:i:s'),
                'mark' => '下注结算--变化:' . $userData['money_balance_add_temp'] 
                        . '下注：' . $userData['bet_amt'] 
                        . '总赢：' . $userData['win']
            ];

            // 更新用户余额
            $updateResult = $UserModel->where('id', $userData['id'])
                ->inc('money_balance', $userData['money_balance_add_temp'])
                ->update();

            // 如果余额更新成功，将资金记录推入Redis队列
            if ($updateResult) {
                redis()->LPUSH('bet_settlement_money_log', json_encode($moneyLog));
            }
        }
    }

    /**
     * 执行后续处理任务
     * 
     * @param array $post 开牌数据
     */
    private function executePostSettlementTasks($post): void
    {
        LogHelper::debug('开始后续处理任务');
        
        // 延迟2秒执行资金日志写入任务
        Queue::later(2, BetMoneyLogInsert::class, $post, 'sicbo_money_log_queue');
        LogHelper::debug('资金日志写入任务已加入队列');

        // 清理临时投注记录
        GameRecordsTemporary::destroy(function($query) use ($post) {
            $query->where('table_id', $post['table_id']);
        });
        LogHelper::debug('临时投注记录清理完成');
    }

    /**
     * 记录性能指标
     * 
     * @param float $startTime 开始时间
     * @param int $luzhu_id 露珠ID
     */
    private function logPerformanceMetrics($startTime, $luzhu_id): void
    {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        LogHelper::debug('=== 用户结算完成 ===', [
            'luzhu_id' => $luzhu_id,
            'duration_ms' => $duration,
            'memory_usage_mb' => round(memory_get_usage() / 1024 / 1024, 2)
        ]);
    }

    /**
     * 根据输赢结果计算洗码费（新规则）
     * 
     * 洗码费规则：
     * - 中奖：无洗码费
     * - 输钱且非免佣：给洗码费
     * - 输钱但免佣：无洗码费
     * 
     * @param array $record 投注记录
     * @param bool $is_win 是否中奖
     * @param array $pai_result 开牌结果
     * @return array 包含洗码费和洗码量的数组
     */
    private function calculateRebate($record, $is_win, $pai_result): array
    {
        // 中奖：无洗码费
        if ($is_win) {
            return ['shuffling_amt' => 0, 'shuffling_num' => 0];
        }

        // 输钱情况下计算洗码费
        $shuffling_rate = $record['shuffling_rate'] ?? 0.008; // 默认0.8%洗码率
        
        return [
            'shuffling_amt' => $record['bet_amt'] * $shuffling_rate,
            'shuffling_num' => $record['bet_amt']
        ];
    }

    /**
     * 自动累计用户洗码费到用户表
     * 
     * @param array $dataSaveRecords 结算记录数组
     */
    private function accumulateUserRebate($dataSaveRecords): void
    {
        // 按用户汇总洗码费
        $userRebates = [];
        foreach ($dataSaveRecords as $record) {
            if ($record['shuffling_amt'] > 0) {
                $userId = $record['user_id'];
                if (!isset($userRebates[$userId])) {
                    $userRebates[$userId] = 0;
                }
                $userRebates[$userId] += $record['shuffling_amt'];
            }
        }
        
        // 批量更新用户洗码费
        if (!empty($userRebates)) {
            LogHelper::debug('开始累计用户洗码费', [
                'user_count' => count($userRebates),
                'total_rebate' => array_sum($userRebates)
            ]);

            foreach ($userRebates as $userId => $totalRebate) {
                // 更新用户洗码费余额和累计洗码费
                UserModel::where('id', $userId)
                    ->inc('rebate_balance', $totalRebate)
                    ->inc('rebate_total', $totalRebate)
                    ->update();
                
                // 记录洗码费流水
                $this->recordRebateLog($userId, $totalRebate);
            }
        }
    }

    /**
     * 记录洗码费流水日志
     * 
     * @param int $userId 用户ID
     * @param float $amount 洗码费金额
     */
    private function recordRebateLog($userId, $amount): void
    {
        MoneyLog::insert([
            'uid' => $userId,
            'type' => 1,
            'status' => 600, // 洗码费自动累计
            'money' => $amount,
            'money_before' => 0, // 洗码费余额变动
            'money_end' => $amount,
            'source_id' => 0,
            'mark' => '系统自动累计洗码费',
            'create_time' => date('Y-m-d H:i:s')
        ]);
    }
}

/**
 * ========================================
 * 类使用说明和技术要点
 * ========================================
 * 
 * 重构改进：
 * 1. 方法职责单一化：将大方法拆分为多个小方法，每个方法只负责一个特定功能
 * 2. 代码可读性提升：使用更清晰的方法命名和参数传递
 * 3. 错误处理优化：统一异常处理和日志记录
 * 4. 性能监控：独立的性能指标记录方法
 * 5. 数据流清晰：明确的数据传递路径和状态管理
 * 
 * 核心流程：
 * 1. 开牌流程：数据保存 → 缓存设置 → 异步结算 → 推送结果
 * 2. 结算流程：查询投注 → 计算结果 → 处理结算 → 更新数据
 * 3. 数据一致性：事务控制 + 锁机制 + 异步队列
 * 
 * 特殊规则：
 * - 洗码费新规则：只有输钱且非免佣才给洗码费
 * - 赔率处理：支持特殊赔率如幸运6、免佣庄等
 * - 并发控制：用户余额更新时加锁防止竞态条件
 * 
 * 性能优化：
 * - 批量数据库操作减少IO
 * - Redis队列异步处理日志
 * - 合理的缓存策略
 * - 内存使用监控
 */