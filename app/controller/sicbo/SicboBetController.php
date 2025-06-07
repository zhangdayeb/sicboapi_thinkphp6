<?php


namespace app\controller\sicbo;

use app\BaseController;
use app\model\sicbo\SicboBetRecords;
use app\model\sicbo\SicboOdds;
use app\model\UserModel;
use app\model\Table;
use app\validate\sicbo\SicboBetValidate;
use think\Response;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 骰宝投注控制器
 * 处理用户投注逻辑、投注验证、余额管理
 */
class SicboBetController extends BaseController
{
    /**
     * 提交用户投注
     * 路由: POST /sicbo/bet/place
     */
    public function placeBet()
    {
        $params = $this->request->only(['table_id', 'game_number', 'bets', 'total_amount']);
        $userId = $this->getCurrentUserId(); // 假设从session或token获取
        $params['user_id'] = $userId;
        
        try {
            validate(SicboBetValidate::class)->scene('place_bet')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $tableId = (int)$params['table_id'];
        $gameNumber = $params['game_number'];
        $bets = $params['bets'];
        $totalAmount = (float)$params['total_amount'];

        // 检查台桌状态
        $table = Table::find($tableId);
        if (!$table || $table->game_type != 9 || $table->status != 1) {
            return json(['code' => 400, 'message' => '台桌不可用']);
        }

        // 检查游戏状态
        $currentGame = cache("sicbo:current_game:{$tableId}");
        if (!$currentGame || $currentGame['game_number'] != $gameNumber || $currentGame['status'] != 'betting') {
            return json(['code' => 400, 'message' => '当前不在投注时间']);
        }

        // 检查是否在投注时间内
        if (time() > $currentGame['betting_end_time']) {
            return json(['code' => 400, 'message' => '投注时间已结束']);
        }

        // 获取用户信息
        $user = UserModel::find($userId);
        if (!$user) {
            return json(['code' => 404, 'message' => '用户不存在']);
        }

        // 验证投注数据
        $validationResult = $this->validateBets($bets, $totalAmount);
        if (!$validationResult['valid']) {
            return json(['code' => 400, 'message' => $validationResult['message']]);
        }

        // 检查用户余额
        if ($user->money_balance < $totalAmount) {
            return json([
                'code' => 400, 
                'message' => '余额不足',
                'current_balance' => $user->money_balance,
                'required_amount' => $totalAmount
            ]);
        }

        // 检查用户是否已在当前局投注（如果需要替换之前的投注）
        $existingBets = SicboBetRecords::getCurrentBets($userId, $gameNumber);
        $existingAmount = 0;
        if (!empty($existingBets)) {
            $existingAmount = array_sum(array_column($existingBets, 'bet_amount'));
        }

        try {
            Db::startTrans();

            // 如果有已存在的投注，先退回金额
            if (!empty($existingBets)) {
                // 取消之前的投注
                SicboBetRecords::cancelCurrentBets($userId, $gameNumber);
                
                // 退回金额
                $user->money_balance += $existingAmount;
                $user->save();
            }

            // 扣除新的投注金额
            $user->money_balance -= $totalAmount;
            $user->save();

            // 创建新的投注记录
            $betRecords = [];
            foreach ($bets as $bet) {
                $oddsInfo = SicboOdds::getOddsByBetType($bet['bet_type']);
                if (!$oddsInfo) {
                    throw new \Exception("投注类型 {$bet['bet_type']} 不存在");
                }

                $betRecords[] = [
                    'user_id' => $userId,
                    'table_id' => $tableId,
                    'game_number' => $gameNumber,
                    'round_number' => $currentGame['round_number'],
                    'bet_type' => $bet['bet_type'],
                    'bet_amount' => $bet['bet_amount'],
                    'odds' => $oddsInfo['odds'],
                    'balance_before' => $user->money_balance + $bet['bet_amount'],
                    'balance_after' => $user->money_balance,
                    'bet_time' => date('Y-m-d H:i:s'),
                ];
            }

            // 批量创建投注记录
            if (!SicboBetRecords::createBatchBets($betRecords)) {
                throw new \Exception('创建投注记录失败');
            }

            Db::commit();

            return json([
                'code' => 200,
                'message' => '投注成功',
                'data' => [
                    'game_number' => $gameNumber,
                    'total_amount' => $totalAmount,
                    'bet_count' => count($bets),
                    'current_balance' => $user->money_balance,
                    'existing_amount_refunded' => $existingAmount
                ]
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'message' => '投注失败：' . $e->getMessage()]);
        }
    }

    /**
     * 修改当前投注
     * 路由: PUT /sicbo/bet/modify
     */
    public function modifyBet()
    {
        $params = $this->request->only(['table_id', 'game_number', 'bets']);
        $userId = $this->getCurrentUserId();
        $params['user_id'] = $userId;
        
        try {
            validate(SicboBetValidate::class)->scene('modify_bet')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $tableId = (int)$params['table_id'];
        $gameNumber = $params['game_number'];
        $bets = $params['bets'];

        // 检查游戏状态
        $currentGame = cache("sicbo:current_game:{$tableId}");
        if (!$currentGame || $currentGame['game_number'] != $gameNumber || $currentGame['status'] != 'betting') {
            return json(['code' => 400, 'message' => '当前不在投注时间']);
        }

        // 检查修改时间限制（投注结束前30秒不允许修改）
        $timeLeft = $currentGame['betting_end_time'] - time();
        if ($timeLeft < 30) {
            return json(['code' => 400, 'message' => '投注即将结束，无法修改']);
        }

        // 获取现有投注
        $existingBets = SicboBetRecords::getCurrentBets($userId, $gameNumber);
        if (empty($existingBets)) {
            return json(['code' => 400, 'message' => '当前局无投注记录']);
        }

        $totalAmount = array_sum(array_column($bets, 'bet_amount'));
        
        // 验证新的投注数据
        $validationResult = $this->validateBets($bets, $totalAmount);
        if (!$validationResult['valid']) {
            return json(['code' => 400, 'message' => $validationResult['message']]);
        }

        // 实际上修改投注就是重新投注，逻辑和placeBet相同
        return $this->placeBet();
    }

    /**
     * 取消当前投注
     * 路由: DELETE /sicbo/bet/cancel
     */
    public function cancelBet()
    {
        $params = $this->request->only(['table_id', 'game_number']);
        $userId = $this->getCurrentUserId();
        $params['user_id'] = $userId;
        
        try {
            validate(SicboBetValidate::class)->scene('cancel_bet')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $tableId = (int)$params['table_id'];
        $gameNumber = $params['game_number'];

        // 检查游戏状态
        $currentGame = cache("sicbo:current_game:{$tableId}");
        if (!$currentGame || $currentGame['game_number'] != $gameNumber || $currentGame['status'] != 'betting') {
            return json(['code' => 400, 'message' => '当前不在投注时间']);
        }

        // 检查取消时间限制（投注结束前10秒不允许取消）
        $timeLeft = $currentGame['betting_end_time'] - time();
        if ($timeLeft < 10) {
            return json(['code' => 400, 'message' => '投注即将结束，无法取消']);
        }

        // 获取现有投注
        $existingBets = SicboBetRecords::getCurrentBets($userId, $gameNumber);
        if (empty($existingBets)) {
            return json(['code' => 400, 'message' => '当前局无投注记录']);
        }

        $refundAmount = array_sum(array_column($existingBets, 'bet_amount'));

        try {
            Db::startTrans();

            // 取消投注记录
            if (!SicboBetRecords::cancelCurrentBets($userId, $gameNumber)) {
                throw new \Exception('取消投注失败');
            }

            // 退回金额
            $user = UserModel::find($userId);
            $user->money_balance += $refundAmount;
            $user->save();

            Db::commit();

            return json([
                'code' => 200,
                'message' => '投注已取消',
                'data' => [
                    'refund_amount' => $refundAmount,
                    'current_balance' => $user->money_balance
                ]
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'message' => '取消投注失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取用户当前投注
     * 路由: GET /sicbo/bet/current
     */
    public function getCurrentBets()
    {
        $params = $this->request->only(['table_id', 'game_number']);
        $userId = $this->getCurrentUserId();
        $params['user_id'] = $userId;
        
        try {
            validate(SicboBetValidate::class)->scene('current_bet')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $gameNumber = $params['game_number'];
        
        $currentBets = SicboBetRecords::getCurrentBets($userId, $gameNumber);
        
        $totalAmount = 0;
        $formattedBets = [];
        
        foreach ($currentBets as $bet) {
            $totalAmount += $bet['bet_amount'];
            $formattedBets[] = [
                'bet_type' => $bet['bet_type'],
                'bet_amount' => $bet['bet_amount'],
                'odds' => $bet['odds'],
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
    }

    /**
     * 获取用户投注历史
     * 路由: GET /sicbo/bet/history
     */
    public function getBetHistory()
    {
        $userId = $this->getCurrentUserId();
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 20);
        $tableId = $this->request->param('table_id/d', 0);
        $betType = $this->request->param('bet_type', '');
        $isWin = $this->request->param('is_win', '');
        $dateRange = $this->request->param('date_range', '');
        
        try {
            validate(SicboBetValidate::class)->scene('bet_history')->check([
                'user_id' => $userId,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        // 构建筛选条件
        $filters = [];
        if ($tableId > 0) {
            $filters['table_id'] = $tableId;
        }
        if (!empty($betType)) {
            $filters['bet_type'] = $betType;
        }
        if ($isWin !== '') {
            $filters['is_win'] = (int)$isWin;
        }
        if (!empty($dateRange)) {
            $dates = explode(',', $dateRange);
            if (count($dates) == 2) {
                $filters['start_date'] = $dates[0];
                $filters['end_date'] = $dates[1];
            }
        }

        $history = SicboBetRecords::getUserBetHistory($userId, $page, $limit, $filters);

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => $history
        ]);
    }

    /**
     * 获取投注详情
     * 路由: GET /sicbo/bet/detail/{bet_id}
     */
    public function getBetDetail()
    {
        $betId = $this->request->param('bet_id/d', 0);
        $userId = $this->getCurrentUserId();
        
        try {
            validate(SicboBetValidate::class)->scene('bet_detail')->check(['bet_id' => $betId]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $bet = SicboBetRecords::where('id', $betId)
            ->where('user_id', $userId)
            ->find();
        
        if (!$bet) {
            return json(['code' => 404, 'message' => '投注记录不存在']);
        }

        // 获取游戏结果
        $gameResult = \app\model\sicbo\SicboGameResults::getByGameNumber($bet['game_number']);

        $betDetail = $bet->toArray();
        $betDetail['game_result'] = $gameResult;
        $betDetail['bet_type_name'] = SicboOdds::getBetTypeName($bet['bet_type']);

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => $betDetail
        ]);
    }

    /**
     * 获取用户余额信息
     * 路由: GET /sicbo/bet/balance
     */
    public function getUserBalance()
    {
        $userId = $this->getCurrentUserId();
        
        $user = UserModel::find($userId);
        if (!$user) {
            return json(['code' => 404, 'message' => '用户不存在']);
        }

        // 计算冻结金额（当前未结算的投注）
        $frozenAmount = SicboBetRecords::where('user_id', $userId)
            ->where('settle_status', SicboBetRecords::SETTLE_STATUS_PENDING)
            ->sum('bet_amount');

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'total_balance' => $user->money_balance,
                'frozen_amount' => (float)$frozenAmount,
                'available_balance' => $user->money_balance - $frozenAmount,
                'update_time' => time()
            ]
        ]);
    }

    /**
     * 获取投注限额信息
     * 路由: GET /sicbo/bet/limits
     */
    public function getBetLimits()
    {
        $tableId = $this->request->param('table_id/d', 0);
        $betType = $this->request->param('bet_type', '');
        
        if (!empty($betType)) {
            // 获取特定投注类型的限额
            $limits = SicboOdds::getBetLimits($betType);
            if (!$limits) {
                return json(['code' => 404, 'message' => '投注类型不存在']);
            }
            
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
            // 获取所有投注类型的限额
            $allOdds = SicboOdds::getAllActiveOdds();
            $limits = [];
            
            foreach ($allOdds as $odd) {
                $limits[$odd['bet_type']] = [
                    'min_bet' => $odd['min_bet'],
                    'max_bet' => $odd['max_bet']
                ];
            }
            
            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'table_id' => $tableId,
                    'limits' => $limits
                ]
            ]);
        }
    }

    /**
     * 预检投注合法性
     * 路由: POST /sicbo/bet/validate
     */
    public function validateBet()
    {
        $params = $this->request->only(['table_id', 'bets']);
        
        try {
            validate(SicboBetValidate::class)->scene('validate_bet')->check($params);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'message' => $e->getError()]);
        }

        $bets = $params['bets'];
        $totalAmount = array_sum(array_column($bets, 'bet_amount'));
        
        $validationResult = $this->validateBets($bets, $totalAmount);
        
        return json([
            'code' => $validationResult['valid'] ? 200 : 400,
            'message' => $validationResult['message'],
            'data' => [
                'valid' => $validationResult['valid'],
                'total_amount' => $totalAmount,
                'bet_count' => count($bets),
                'details' => $validationResult['details'] ?? []
            ]
        ]);
    }

    /**
     * 验证投注数据
     */
    private function validateBets(array $bets, float $totalAmount): array
    {
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

            // 检查投注类型是否存在
            $odds = SicboOdds::getOddsByBetType($betType);
            if (!$odds) {
                return ['valid' => false, 'message' => "投注类型 {$betType} 不存在"];
            }

            // 检查投注金额是否在限额范围内
            if (!SicboOdds::validateBetAmount($betType, $betAmount)) {
                return [
                    'valid' => false, 
                    'message' => "投注类型 {$betType} 金额超出限额范围 {$odds['min_bet']}-{$odds['max_bet']}"
                ];
            }

            $details[] = [
                'bet_type' => $betType,
                'bet_amount' => $betAmount,
                'odds' => $odds['odds'],
                'potential_win' => $betAmount * $odds['odds'],
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
    }

    /**
     * 获取当前用户ID
     * 实际项目中应该从认证中间件或session中获取
     */
    private function getCurrentUserId(): int
    {
        // 临时模拟，实际应该从认证系统获取
        return $this->request->header('user-id', 1);
    }
}