<?php
// ==========================================
// app/validate/sicbo/SicboBetValidate.php
// 投注数据验证器
// ==========================================

declare(strict_types=1);

namespace app\validate\sicbo;

use think\Validate;

class SicboBetValidate extends Validate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        // 基础投注参数
        'table_id'     => 'require|integer|gt:0',
        'game_number'  => 'require|max:50',
        'user_id'      => 'require|integer|gt:0',
        'total_amount' => 'require|float|egt:0',
        
        // 投注数据
        'bets'         => 'require|array',
        'bet_type'     => 'require|max:50',
        'bet_amount'   => 'require|float|gt:0',
        
        // 查询参数
        'page'         => 'integer|egt:1',
        'limit'        => 'integer|between:1,100',
        'date_range'   => 'max:100',
        
        // 修改投注
        'bet_id'       => 'integer|gt:0',
        'action'       => 'require|in:modify,cancel',
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'table_id.require'     => '台桌ID不能为空',
        'table_id.integer'     => '台桌ID必须是整数',
        'table_id.gt'          => '台桌ID必须大于0',
        
        'game_number.require'  => '游戏局号不能为空',
        'game_number.max'      => '游戏局号长度不能超过50字符',
        
        'user_id.require'      => '用户ID不能为空',
        'user_id.integer'      => '用户ID必须是整数',
        'user_id.gt'           => '用户ID必须大于0',
        
        'total_amount.require' => '投注总额不能为空',
        'total_amount.float'   => '投注总额必须是数字',
        'total_amount.egt'     => '投注总额不能为负数',
        
        'bets.require'         => '投注数据不能为空',
        'bets.array'           => '投注数据格式错误',
        
        'bet_type.require'     => '投注类型不能为空',
        'bet_type.max'         => '投注类型长度不能超过50字符',
        
        'bet_amount.require'   => '投注金额不能为空',
        'bet_amount.float'     => '投注金额必须是数字',
        'bet_amount.gt'        => '投注金额必须大于0',
        
        'page.integer'         => '页码必须是整数',
        'page.egt'             => '页码必须大于等于1',
        
        'limit.integer'        => '每页数量必须是整数',
        'limit.between'        => '每页数量必须在1-100之间',
        
        'action.require'       => '操作类型不能为空',
        'action.in'            => '操作类型只能是modify或cancel',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        // 投注场景
        'place_bet' => ['table_id', 'game_number', 'user_id', 'bets', 'total_amount'],
        
        // 修改投注场景
        'modify_bet' => ['table_id', 'game_number', 'user_id', 'bets'],
        
        // 取消投注场景
        'cancel_bet' => ['table_id', 'game_number', 'user_id'],
        
        // 查询当前投注场景
        'current_bet' => ['table_id', 'game_number', 'user_id'],
        
        // 投注历史场景
        'bet_history' => ['user_id', 'page', 'limit'],
        
        // 投注详情场景
        'bet_detail' => ['bet_id'],
        
        // 验证投注场景
        'validate_bet' => ['table_id', 'bets'],
    ];

    /**
     * 自定义验证方法 - 验证投注类型
     */
    protected function checkBetType($value, $rule, $data = [])
    {
        // TODO: 实现投注类型验证逻辑
        return true;
    }

    /**
     * 自定义验证方法 - 验证投注金额
     */
    protected function checkBetAmount($value, $rule, $data = [])
    {
        // TODO: 实现投注金额验证逻辑
        return true;
    }
}