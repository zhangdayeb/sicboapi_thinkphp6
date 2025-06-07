<?php
// ==========================================
// app/validate/sicbo/SicboGameValidate.php  
// 游戏数据验证器
// ==========================================


namespace app\validate\sicbo;

use think\Validate;

class SicboGameValidate extends Validate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        // 台桌信息
        'table_id'        => 'require|integer|gt:0',
        'game_number'     => 'max:50',
        'round_number'    => 'integer|egt:1',
        
        // 骰子数据
        'dice1'           => 'require|integer|between:1,6',
        'dice2'           => 'require|integer|between:1,6', 
        'dice3'           => 'require|integer|between:1,6',
        'total_points'    => 'integer|between:3,18',
        
        // 游戏状态
        'status'          => 'in:waiting,betting,dealing,result',
        'betting_time'    => 'integer|between:10,120',
        
        // 查询参数
        'limit'           => 'integer|between:1,100',
        'page'            => 'integer|egt:1',
        'date_range'      => 'max:100',
        'stat_type'       => 'in:daily,weekly,monthly',
        
        // 时间参数
        'start_date'      => 'date',
        'end_date'        => 'date',
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'table_id.require'     => '台桌ID不能为空',
        'table_id.integer'     => '台桌ID必须是整数',
        'table_id.gt'          => '台桌ID必须大于0',
        
        'round_number.integer' => '轮次号必须是整数',
        'round_number.egt'     => '轮次号必须大于等于1',
        
        'dice1.require'        => '骰子1点数不能为空',
        'dice1.integer'        => '骰子1点数必须是整数',
        'dice1.between'        => '骰子1点数必须在1-6之间',
        
        'dice2.require'        => '骰子2点数不能为空',
        'dice2.integer'        => '骰子2点数必须是整数',
        'dice2.between'        => '骰子2点数必须在1-6之间',
        
        'dice3.require'        => '骰子3点数不能为空',
        'dice3.integer'        => '骰子3点数必须是整数',
        'dice3.between'        => '骰子3点数必须在1-6之间',
        
        'total_points.integer' => '总点数必须是整数',
        'total_points.between' => '总点数必须在3-18之间',
        
        'status.in'            => '游戏状态值无效',
        'betting_time.integer' => '投注时间必须是整数',
        'betting_time.between' => '投注时间必须在10-120秒之间',
        
        'stat_type.in'         => '统计类型只能是daily、weekly或monthly',
        
        'start_date.date'      => '开始日期格式错误',
        'end_date.date'        => '结束日期格式错误',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        // 获取台桌信息场景
        'table_info' => ['table_id'],
        
        // 开始新游戏场景
        'start_game' => ['table_id', 'betting_time'],
        
        // 停止投注场景
        'stop_betting' => ['table_id', 'game_number'],
        
        // 公布结果场景
        'announce_result' => ['table_id', 'game_number', 'dice1', 'dice2', 'dice3'],
        
        // 游戏历史场景
        'game_history' => ['table_id', 'limit'],
        
        // 统计数据场景
        'statistics' => ['table_id', 'stat_type'],
        
        // 投注统计场景
        'bet_stats' => ['table_id', 'game_number'],
    ];

    /**
     * 自定义验证方法 - 验证骰子组合
     */
    protected function checkDiceCombination($value, $rule, $data = [])
    {
        // TODO: 实现骰子组合验证逻辑
        return true;
    }

    /**
     * 自定义验证方法 - 验证游戏状态
     */
    protected function checkGameStatus($value, $rule, $data = [])
    {
        // TODO: 实现游戏状态验证逻辑
        return true;
    }
}