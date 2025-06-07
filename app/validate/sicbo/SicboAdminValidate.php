<?php
// ==========================================
// app/validate/sicbo/SicboAdminValidate.php
// 管理操作验证器
// ==========================================


namespace app\validate\sicbo;

use think\Validate;

class SicboAdminValidate extends Validate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        // 台桌管理
        'table_id'         => 'require|integer|gt:0',
        'table_title'      => 'max:100',
        'table_status'     => 'in:0,1',
        'game_config'      => 'array',
        
        // 荷官操作
        'dealer_id'        => 'integer|gt:0',
        'action'           => 'require|in:start,stop,pause,resume,force_end',
        'reason'           => 'max:200',
        'betting_duration' => 'integer|between:10,120',
        
        // 骰子结果
        'dice1'            => 'require|integer|between:1,6',
        'dice2'            => 'require|integer|between:1,6',
        'dice3'            => 'require|integer|between:1,6',
        
        // 赔率配置
        'bet_type'         => 'require|max:50',
        'odds'             => 'require|float|gt:0',
        'min_bet'          => 'require|float|egt:0',
        'max_bet'          => 'require|float|gt:0',
        'status'           => 'in:0,1',
        
        // 报表查询
        'report_type'      => 'require|in:financial,user_behavior,game_stats',
        'date_range'       => 'require|max:100',
        'start_date'       => 'require|date',
        'end_date'         => 'require|date',
        'user_type'        => 'in:all,vip,normal',
        
        // 系统配置
        'config_key'       => 'require|max:100',
        'config_value'     => 'require',
        'config_type'      => 'in:string,integer,float,boolean,json',
        
        // 监控数据
        'monitor_type'     => 'in:realtime,daily,weekly',
        'alert_level'      => 'in:info,warning,error,critical',
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'table_id.require'         => '台桌ID不能为空',
        'table_id.integer'         => '台桌ID必须是整数',
        'table_id.gt'              => '台桌ID必须大于0',
        
        'table_title.max'          => '台桌标题长度不能超过100字符',
        'table_status.in'          => '台桌状态只能是0或1',
        'game_config.array'        => '游戏配置必须是数组格式',
        
        'action.require'           => '操作类型不能为空',
        'action.in'                => '操作类型无效',
        'reason.max'               => '操作原因长度不能超过200字符',
        'betting_duration.between' => '投注时长必须在10-120秒之间',
        
        'dice1.require'            => '骰子1点数不能为空',
        'dice1.between'            => '骰子1点数必须在1-6之间',
        'dice2.require'            => '骰子2点数不能为空',
        'dice2.between'            => '骰子2点数必须在1-6之间',
        'dice3.require'            => '骰子3点数不能为空',
        'dice3.between'            => '骰子3点数必须在1-6之间',
        
        'bet_type.require'         => '投注类型不能为空',
        'bet_type.max'             => '投注类型长度不能超过50字符',
        'odds.require'             => '赔率不能为空',
        'odds.float'               => '赔率必须是数字',
        'odds.gt'                  => '赔率必须大于0',
        'min_bet.require'          => '最小投注不能为空',
        'max_bet.require'          => '最大投注不能为空',
        'max_bet.gt'               => '最大投注必须大于0',
        
        'report_type.require'      => '报表类型不能为空',
        'report_type.in'           => '报表类型无效',
        'date_range.require'       => '日期范围不能为空',
        'start_date.require'       => '开始日期不能为空',
        'start_date.date'          => '开始日期格式错误',
        'end_date.require'         => '结束日期不能为空',
        'end_date.date'            => '结束日期格式错误',
        
        'config_key.require'       => '配置键名不能为空',
        'config_key.max'           => '配置键名长度不能超过100字符',
        'config_value.require'     => '配置值不能为空',
        'config_type.in'           => '配置类型无效',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        // 台桌管理场景
        'update_table'     => ['table_id', 'table_title', 'table_status', 'game_config'],
        'toggle_table'     => ['table_id', 'action'],
        
        // 荷官操作场景
        'dealer_start'     => ['table_id', 'dealer_id', 'betting_duration'],
        'dealer_input'     => ['table_id', 'game_number', 'dice1', 'dice2', 'dice3', 'dealer_id'],
        'dealer_force_end' => ['table_id', 'game_number', 'reason', 'dealer_id'],
        
        // 赔率配置场景
        'update_odds'      => ['bet_type', 'odds', 'min_bet', 'max_bet', 'status'],
        
        // 报表查询场景
        'financial_report'    => ['report_type', 'start_date', 'end_date', 'table_id'],
        'user_behavior_report'=> ['report_type', 'start_date', 'end_date', 'user_type'],
        'game_stats_report'   => ['report_type', 'start_date', 'end_date', 'table_id'],
        
        // 系统配置场景
        'update_system_config' => ['config_key', 'config_value', 'config_type'],
        
        // 监控数据场景
        'realtime_monitor' => ['table_id', 'monitor_type'],
    ];

    /**
     * 自定义验证方法 - 验证管理员权限
     */
    protected function checkAdminPermission($value, $rule, $data = [])
    {
        // TODO: 实现管理员权限验证逻辑
        return true;
    }

    /**
     * 自定义验证方法 - 验证荷官权限
     */
    protected function checkDealerPermission($value, $rule, $data = [])
    {
        // TODO: 实现荷官权限验证逻辑
        return true;
    }

    /**
     * 自定义验证方法 - 验证日期范围
     */
    protected function checkDateRange($value, $rule, $data = [])
    {
        // TODO: 实现日期范围验证逻辑
        return true;
    }

    /**
     * 自定义验证方法 - 验证配置值
     */
    protected function checkConfigValue($value, $rule, $data = [])
    {
        // TODO: 实现配置值验证逻辑
        return true;
    }
}