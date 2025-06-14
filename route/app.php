<?php
use think\facade\Route;

/**
 * ========================================
 * 博彩游戏系统路由配置
 * ========================================
 * 主要包含：骰宝、龙虎斗、牛牛等游戏的API路由
 * 支持荷官操作、用户投注、游戏结算等功能
 */

// ========================================
// 荷官操作相关路由
// ========================================

/**
 * 露珠数据获取相关路由
 * 露珠：记录游戏历史结果的数据表格
 */
// 获取露珠列表数据
Route::rule('sicbo/get_table/get_data$', '/game.GetForeignTableInfo/get_lz_list');

// 获取荷官端露珠数据
Route::rule('sicbo/get_table/get_hg_data$', '/game.GetForeignTableInfo/get_hg_lz_list');

// 获取电投端露珠数据
Route::rule('api/diantou/table/getData', '/game.GetForeignTableInfo/get_hg_data_list');

// 获取电投端视频流地址
Route::rule('api/diantou/table/getTableVideo', '/game.GetForeignTableInfo/get_hg_video_list');

/**
 * 台桌基础信息路由
 */
// 获取台桌视频流信息
Route::rule('sicbo/get_table/get_table_video', '/game.GetForeignTableInfo/get_table_video');

// 获取台桌列表
Route::rule('sicbo/get_table/list$', '/game.GetForeignTableInfo/get_table_list');

// 获取台桌统计信息（庄闲和次数等）
Route::rule('sicbo/get_table/get_table_count$', '/game.GetForeignTableInfo/get_table_count');

// 获取当前台桌详细信息（靴号、铺号等）
Route::rule('sicbo/get_table/table_info$', '/game.GetForeignTableInfo/get_table_info');

// 获取单个用户详细信息
Route::rule('sicbo/user/info$', '/game.GetForeignTableInfo/get_user_info');
/**
 * 荷官开牌操作路由
 */
// 荷官手动开牌设置露珠数据
Route::rule('sicbo/get_table/post_data$', '/game.GetForeignTableInfo/set_post_data');

// 获取扑克牌详细信息
Route::rule('sicbo/pai/info$', '/game.GetForeignTableInfo/get_pai_info');

/**
 * 游戏控制相关路由
 */
// 发送开局信号（开始投注倒计时）
Route::rule('sicbo/start/signal$', '/game.GetForeignTableInfo/set_start_signal');

// 发送结束信号（停止投注）
Route::rule('sicbo/end/signal$', '/game.GetForeignTableInfo/set_end_signal');

// 设置洗牌状态
Route::rule('sicbo/get_table/wash_brand$', '/game.GetForeignTableInfo/get_table_wash_brand');

// 手动设置靴号（新一轮游戏开始）
Route::rule('sicbo/get_table/add_xue$', '/game.GetForeignTableInfo/set_xue_number');

/**
 * 露珠管理操作路由
 */
// 删除指定露珠记录
Route::rule('sicbo/get_table/clear_lu_zhu$', '/game.GetForeignTableInfo/lz_delete');

// 清空指定台桌的所有露珠记录
Route::rule('sicbo/get_table/clear_lu_zhu_one_table$', '/game.GetForeignTableInfo/lz_table_delete');

// ========================================
// 用户游戏相关路由
// ========================================

/**
 * 用户投注相关路由
 */
// 用户下注接口
Route::rule('sicbo/bet/order$', '/order.Order/user_bet_order');

// 获取用户当前投注记录
Route::rule('sicbo/current/record$', '/order.Order/order_current_record');

/**
 * 游戏信息查询路由
 */
// 获取指定露珠的扑克牌型信息
Route::rule('sicbo/game/poker$', '/game.GameInfo/get_poker_type');

// ========================================
// 测试环境路由（仅开发调试用）
// ========================================
// 测试露珠数据接口
Route::rule('api/test/luzhu', '/game.GetForeignTableInfo/testluzhu');


// ========================================
// 系统基础路由
// ========================================
// 首页路由
Route::rule('/$', '/index/index');

/**
 * ========================================
 * 路由说明
 * ========================================
 * 
 * 核心术语解释：
 * - 露珠(LuZhu)：记录每局游戏结果的历史数据表
 * - 台桌(Table)：游戏桌，可同时运行多个
 * - 靴号(XueNumber)：一副牌的编号，类似场次
 * - 铺号(PuNumber)：当前靴内的局数编号
 * - 荷官(Dealer)：负责发牌开牌的操作员
 * - 电投(DianTou)：电子投注终端
 * 
 * 游戏流程：
 * 1. 荷官发送开局信号开始倒计时
 * 2. 用户在倒计时内进行投注
 * 3. 倒计时结束后荷官开牌
 * 4. 系统自动计算结果并结算
 * 5. 将结果记录到露珠表中
 */