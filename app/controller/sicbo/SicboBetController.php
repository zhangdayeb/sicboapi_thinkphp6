<?php

namespace app\controller\sicbo;

use app\BaseController;
use think\facade\Db;
use think\facade\Cache;
use think\exception\ValidateException;

/**
 * 骰宝投注控制器 - 调试版本
 * 主要函数保持简单，复杂逻辑移到底部方便调试
 */
class SicboBetController extends BaseController
{
    // 投注状态常量
    const SETTLE_STATUS_PENDING = 0;    // 未结算
    const SETTLE_STATUS_WIN = 1;        // 中奖
    const SETTLE_STATUS_LOSE = 2;       // 未中奖
    const SETTLE_STATUS_CANCELLED = 3;  // 已取消

    /**
     * 提交用户投注 - 简化版，便于调试
     * 路由: POST /sicbo/bet/place
     */
    public function placeBet()
    {
        try {
            $params = $this->request->only(['table_id', 'game_number', 'bets', 'total_amount']);
            $userId = $this->getCurrentUserId();
            
            // 基础参数验证
            if (empty($params['table_id']) || empty($params['game_number'])) {
                return json(['code' => 400, 'message' => '参数不完整']);
            }

            $tableId = (int)$params['table_id'];
            $gameNumber = $params['game_number'];
            $bets = $params['bets'] ?? [];
            $totalAmount = (float)($params['total_amount'] ?? 0);

            // 调用底部的复杂验证函数
            $validationResult = $this->validateBetsDetailed($bets, $totalAmount);
            if (!$validationResult['valid']) {
                return json(['code' => 400, 'message' => $validationResult['message']]);
            }

            // 调用底部的投注处理函数
            return $this->processBetPlacement($userId, $tableId, $gameNumber, $bets, $totalAmount);

        } catch (\Exception $e) {
            return json([
                'code' => 500, 
                'message' => '投注失败：' . $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 修改当前投注 - 简化版
     * 路由: PUT /sicbo/bet/modify
     */
    public function modifyBet()
    {
        try {
            $params = $this->request->only(['table_id', 'game_number', 'bets']);
            $userId = $this->getCurrentUserId();
            
            if (empty($params['table_id']) || empty($params['game_number']) || empty($params['bets'])) {
                return json(['code' => 400, 'message' => '参数不完整']);
            }

            // 调用底部的修改处理函数
            return $this->processBetModification($userId, $params);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '修改投注失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 取消当前投注 - 简化版
     * 路由: DELETE /sicbo/bet/cancel
     */
    public function cancelBet()
    {
        try {
            $params = $this->request->only(['table_id', 'game_number']);
            $userId = $this->getCurrentUserId();
            
            if (empty($params['table_id']) || empty($params['game_number'])) {
                return json(['code' => 400, 'message' => '参数不完整']);
            }

            // 调用底部的取消处理函数
            return $this->processBetCancellation($userId, $params);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '取消投注失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取用户当前投注 - 简化版
     * 路由: GET /sicbo/bet/current
     */
    public function getCurrentBets()
    {
        try {
            $params = $this->request->only(['table_id', 'game_number']);
            $userId = $this->getCurrentUserId();
            
            if (empty($params['game_number'])) {
                return json(['code' => 400, 'message' => '游戏局号不能为空']);
            }

            $gameNumber = $params['game_number'];
            
            // 简单查询，避免复杂逻辑
            $bets = Db::name('sicbo_bet_records')
                ->where('user_id', $userId)
                ->where('game_number', $gameNumber)
                ->where('settle_status', self::SETTLE_STATUS_PENDING)
                ->select()
                ->toArray();
            
            $totalAmount = array_sum(array_column($bets, 'bet_amount'));
            
            $formattedBets = [];
            foreach ($bets as $bet) {
                $formattedBets[] = [
                    'bet_type' => $bet['bet_type'],
                    'bet_amount' => (float)$bet['bet_amount'],
                    'odds' => (float)$bet['odds'],
                    'potential_win' => $bet['bet_amount'] * $bet['odds'],
                    'bet_time' => $bet['bet_time'],
                ];
            }

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'game_number' => $gameNumber,
                    'bets' => $formattedBets,
                    'total_amount' => $totalAmount,
                    'bet_count' => count($formattedBets)
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '获取当前投注失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取用户投注历史 - 简化版
     * 路由: GET /sicbo/bet/history
     */
    public function getBetHistory()
    {
        try {
            $userId = $this->getCurrentUserId();
            $page = $this->request->param('page/d', 1);
            $limit = $this->request->param('limit/d', 20);
            
            // 参数验证
            if ($page <= 0) $page = 1;
            if ($limit <= 0 || $limit > 100) $limit = 20;

            // 调用底部的历史查询函数
            return $this->queryBetHistory($userId, $page, $limit);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '获取投注历史失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取投注详情 - 简化版
     * 路由: GET /sicbo/bet/detail/{bet_id}
     */
    public function getBetDetail()
    {
        try {
            $betId = $this->request->param('bet_id/d', 0);
            $userId = $this->getCurrentUserId();
            
            if ($betId <= 0) {
                return json(['code' => 400, 'message' => '投注ID无效']);
            }

            // 调用底部的详情查询函数
            return $this->queryBetDetail($betId, $userId);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '获取投注详情失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取用户余额信息 - 保持简单
     * 路由: GET /sicbo/bet/balance
     */
    public function getUserBalance()
    {
        try {
            $userId = $this->getCurrentUserId();

            // 简单查询用户余额
            $user = Db::name('user')
                ->where('id', $userId)
                ->field('money_balance')
                ->find();

            if (!$user) {
                return json(['code' => 404, 'message' => '用户不存在']);
            }

            // 计算冻结金额
            $frozenAmount = Db::name('sicbo_bet_records')
                ->where('user_id', $userId)
                ->where('settle_status', self::SETTLE_STATUS_PENDING)
                ->sum('bet_amount');

            $frozenAmount = (float)($frozenAmount ?? 0);
            $totalBalance = (float)$user['money_balance'];

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'total_balance' => $totalBalance,
                    'frozen_amount' => $frozenAmount,
                    'available_balance' => $totalBalance - $frozenAmount,
                    'currency' => 'CNY',
                    'last_update' => date('Y-m-d H:i:s'),
                    'update_time' => time()
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '获取余额失败：' . $e->getMessage(),
                'debug' => [
                    'user_id' => $userId ?? 'unknown',
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 获取投注限额信息 - 保持简单
     * 路由: GET /sicbo/bet/limits
     */
    public function getBetLimits()
    {
        try {
            $tableId = $this->request->param('table_id/d', 0);
            $betType = $this->request->param('bet_type', '');

            // 简单返回固定限额，避免复杂查询
            $defaultLimits = [
                'basic' => ['min_bet' => 10, 'max_bet' => 50000],
                'total' => ['min_bet' => 10, 'max_bet' => 10000],
                'single' => ['min_bet' => 10, 'max_bet' => 20000],
                'pair' => ['min_bet' => 10, 'max_bet' => 5000],
                'triple' => ['min_bet' => 10, 'max_bet' => 1000],
                'combo' => ['min_bet' => 10, 'max_bet' => 8000]
            ];

            if (!empty($betType)) {
                $category = $this->getBetTypeCategory($betType);
                $limits = $defaultLimits[$category] ?? $defaultLimits['basic'];
                
                return json([
                    'code' => 200,
                    'message' => 'success',
                    'data' => [
                        'bet_type' => $betType,
                        'min_bet' => $limits['min_bet'],
                        'max_bet' => $limits['max_bet']
                    ]
                ]);
            } else {
                return json([
                    'code' => 200,
                    'message' => 'success',
                    'data' => [
                        'table_id' => $tableId,
                        'limits' => $defaultLimits
                    ]
                ]);
            }

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '获取投注限额失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 预检投注合法性 - 保持简单
     * 路由: POST /sicbo/bet/validate
     */
    public function validateBet()
    {
        try {
            $params = $this->request->only(['table_id', 'bets']);
            
            if (empty($params['table_id']) || empty($params['bets'])) {
                return json(['code' => 400, 'message' => '参数不完整']);
            }

            $bets = $params['bets'];
            $totalAmount = array_sum(array_column($bets, 'bet_amount'));
            
            // 简单验证
            $isValid = !empty($bets) && $totalAmount > 0;
            
            return json([
                'code' => $isValid ? 200 : 400,
                'message' => $isValid ? '投注数据验证通过' : '投注数据无效',
                'data' => [
                    'valid' => $isValid,
                    'total_amount' => $totalAmount,
                    'bet_count' => count($bets)
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '验证投注失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * ========================================
     * 基础辅助方法 - 保持简单
     * ========================================
     */

    /**
     * 获取当前用户ID
     */
    private function getCurrentUserId(): int
    {
        $userId = $this->request->header('X-User-ID', 0);
        if (!$userId) {
            $userId = $this->request->header('user-id', 0);
        }
        if (!$userId) {
            $userId = $this->request->param('user_id', 1);
        }
        return (int)$userId;
    }

    /**
     * 根据投注类型获取分类
     */
    private function getBetTypeCategory(string $betType): string
    {
        if (in_array($betType, ['big', 'small', 'odd', 'even'])) {
            return 'basic';
        } elseif (strpos($betType, 'total-') === 0) {
            return 'total';
        } elseif (strpos($betType, 'single-') === 0) {
            return 'single';
        } elseif (strpos($betType, 'pair-') === 0) {
            return 'pair';
        } elseif (strpos($betType, 'triple-') === 0 || $betType === 'any-triple') {
            return 'triple';
        } elseif (strpos($betType, 'combo-') === 0) {
            return 'combo';
        } else {
            return 'basic';
        }
    }

    /**
     * ========================================
     * 复杂业务逻辑函数 - 放在底部方便调试
     * ========================================
     */

    /**
     * 详细验证投注数据 - 复杂函数1
     * 问题函数，需要逐步调试
     */
    private function validateBetsDetailed(array $bets, float $totalAmount): array
    {
        try {
            if (empty($bets)) {
                return ['valid' => false, 'message' => '投注数据不能为空'];
            }

            if ($totalAmount <= 0) {
                return ['valid' => false, 'message' => '投注金额必须大于0'];
            }

            $details = [];
            $calculatedTotal = 0;

            foreach ($bets as $bet) {
                if (!isset($bet['bet_type']) || !isset($bet['bet_amount'])) {
                    return ['valid' => false, 'message' => '投注数据格式错误'];
                }

                $betType = $bet['bet_type'];
                $betAmount = (float)$bet['bet_amount'];
                $calculatedTotal += $betAmount;

                // 简化：先返回固定赔率，避免数据库查询出错
                $odds = 1.0;
                
                $details[] = [
                    'bet_type' => $betType,
                    'bet_amount' => $betAmount,
                    'odds' => $odds,
                    'potential_win' => $betAmount * $odds,
                    'valid' => true
                ];
            }

            // 检查总金额是否一致
            if (abs($calculatedTotal - $totalAmount) > 0.01) {
                return ['valid' => false, 'message' => '投注总金额计算错误'];
            }

            return [
                'valid' => true,
                'message' => '投注数据验证通过',
                'details' => $details
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false, 
                'message' => '验证失败：' . $e->getMessage()
            ];
        }
    }

    /**
     * 处理投注提交 - 复杂函数2
     * 问题函数，需要逐步调试
     */
    private function processBetPlacement(int $userId, int $tableId, string $gameNumber, array $bets, float $totalAmount): array
    {
        try {
            // 简化检查：只验证用户是否存在
            $user = Db::name('user')->where('id', $userId)->find();
            if (!$user) {
                return json(['code' => 404, 'message' => '用户不存在']);
            }

            $userBalance = (float)$user['money_balance'];
            if ($userBalance < $totalAmount) {
                return json([
                    'code' => 400, 
                    'message' => '余额不足',
                    'data' => [
                        'current_balance' => $userBalance,
                        'required_amount' => $totalAmount
                    ]
                ]);
            }

            // 暂时模拟投注成功，不实际扣款
            return json([
                'code' => 200,
                'message' => '投注成功（模拟）',
                'data' => [
                    'bet_id' => $gameNumber . '_' . $userId . '_' . time(),
                    'game_number' => $gameNumber,
                    'total_amount' => $totalAmount,
                    'new_balance' => $userBalance, // 暂不扣款
                    'bet_count' => count($bets),
                    'bet_time' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '处理投注失败：' . $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * 处理投注修改 - 复杂函数3
     * 问题函数，需要逐步调试
     */
    private function processBetModification(int $userId, array $params): array
    {
        try {
            // 暂时返回成功响应
            return json([
                'code' => 200,
                'message' => '投注修改成功（模拟）',
                'data' => [
                    'game_number' => $params['game_number'],
                    'user_id' => $userId,
                    'action' => 'modify'
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '处理修改失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 处理投注取消 - 复杂函数4
     * 问题函数，需要逐步调试
     */
    private function processBetCancellation(int $userId, array $params): array
    {
        try {
            // 暂时返回成功响应
            return json([
                'code' => 200,
                'message' => '投注取消成功（模拟）',
                'data' => [
                    'refund_amount' => 0,
                    'current_balance' => 10000
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '处理取消失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 查询投注历史 - 复杂函数5
     * 问题函数，需要逐步调试
     */
    private function queryBetHistory(int $userId, int $page, int $limit): array
    {
        try {
            // 简单查询，避免复杂的筛选
            $offset = ($page - 1) * $limit;
            
            $total = Db::name('sicbo_bet_records')
                ->where('user_id', $userId)
                ->count();

            $history = Db::name('sicbo_bet_records')
                ->where('user_id', $userId)
                ->order('id desc')
                ->limit($offset, $limit)
                ->select()
                ->toArray();

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit),
                    'data' => $history,
                    'has_more' => $page * $limit < $total
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '查询历史失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 查询投注详情 - 复杂函数6
     * 问题函数，需要逐步调试
     */
    private function queryBetDetail(int $betId, int $userId): array
    {
        try {
            $bet = Db::name('sicbo_bet_records')
                ->where('id', $betId)
                ->where('user_id', $userId)
                ->find();
            
            if (!$bet) {
                return json(['code' => 404, 'message' => '投注记录不存在']);
            }

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'id' => $bet['id'],
                    'game_number' => $bet['game_number'],
                    'bet_type' => $bet['bet_type'],
                    'bet_amount' => (float)$bet['bet_amount'],
                    'odds' => (float)$bet['odds'],
                    'bet_time' => $bet['bet_time']
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => '查询详情失败：' . $e->getMessage()
            ]);
        }
    }
}