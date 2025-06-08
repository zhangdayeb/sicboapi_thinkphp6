<?php

namespace app\service;
use app\controller\common\LogHelper;
use think\facade\Db;
/**
 * ========================================
 * 骰宝开牌计算服务类
 * ========================================
 * 
 * 功能概述：
 * - 处理骰宝游戏的牌面计算逻辑
 * - 计算庄闲家点数和各种特殊牌型
 * - 判断游戏胜负结果和特殊投注
 * - 提供牌面信息的格式化输出
 * 
 * 
 * @package app\service
 * @author  系统开发团队
 * @version 2.0
 */
class OpenPaiCalculationService
{
    /**
     * ========================================
     * 执行完整的牌面计算流程
     * ========================================
     * 
     * 主入口方法，按顺序执行三个计算步骤，
     * 将原始牌面数据转化为完整的游戏结果
     * 输入: ["1"=>"1", "2"=>"2","3"=>"2"]
     * @param array $pai 原始牌面数据数组
     * @return array 完整的游戏计算结果
     */
    public function runs(array $pai): array
    {
        LogHelper::debug('=== 开牌计算开始 ===');
        LogHelper::debug('原始牌面数据', $pai);
                
        $calculation_start = $this->calculation_start($pai);
        LogHelper::debug('中间计算结果', $calculation_start);
        
        $result = $this->calculation_result($calculation_start);
        
        LogHelper::debug('开牌计算完成', [
            'win_array' => $result['win_array'],
            'win_types' => array_map([$this, 'user_pai_chinese'], $result['win_array'])
        ]);
        LogHelper::debug('===============================最终计算结果==========================================');  
        LogHelper::debug(json_encode($result));
        LogHelper::debug('=== 开牌计算完成 ===');
        
        return $result;
    }


    /**
     * ========================================
     * 第二步：计算庄闲点数和特殊牌型
     * ========================================
     * 
     * 基于整理后的牌面数据，计算以下内容：
     * - 庄家和闲家的点数总和
     * - 庄对、闲对的判断
     * - 幸运6相关的牌数计算
     * - 大小判断的基础数据
     * - 牌面的中文描述字符串
     * 
     * @param array $data 经过calculation()处理的数据
     * @return array 包含所有中间计算结果的数组
     */
    public function calculation_start(array $data): array
    {
        $dice1 = 1;
        $dice2 = 1;
        $dice3 = 1;
        foreach ($data as $key => $value) {
            // 确保键为整数类型
            $key = intval($key);
            $value = intval($value);
            
            if($key == 1){
                $dice1 = $value;
            }

            if($key == 2){
                $dice2 = $value;
            }

            if($key == 3){
                $dice3 = $value;
            }
        }
        $total = $dice1 + $dice2 + $dice3;
        // ========================================
        // 初始化计算变量 - 基础组
        // ========================================
        $basic_small = 0;
        $basic_big = 0;
        $basic_odd = 0;
        $basic_even = 0;
        // ========================================
        // 初始化计算变量 - 总和组
        // ========================================
        $total_total_4 = 0;
        $total_total_5 = 0;
        $total_total_6 = 0;
        $total_total_7 = 0;
        $total_total_8 = 0;
        $total_total_9 = 0;
        $total_total_10 = 0;
        $total_total_11 = 0;
        $total_total_12 = 0;
        $total_total_13 = 0;
        $total_total_14 = 0;
        $total_total_15 = 0;
        $total_total_16 = 0;
        $total_total_17 = 0;
        // ========================================
        // 初始化计算变量 - 单个的
        // ========================================
        $single_single_1 = 0;
        $single_single_2 = 0;
        $single_single_3 = 0;
        $single_single_4 = 0;
        $single_single_5 = 0;
        $single_single_6 = 0;
        // ========================================
        // 初始化计算变量 - 对子
        // ========================================
        $pair_pair_1 = 0;
        $pair_pair_2 = 0;
        $pair_pair_3 = 0;
        $pair_pair_4 = 0;
        $pair_pair_5 = 0;
        $pair_pair_6 = 0;
        // ========================================
        // 初始化计算变量 - 三同
        // ========================================
        $triple_triple_1 = 0;
        $triple_triple_2 = 0;
        $triple_triple_3 = 0;
        $triple_triple_4 = 0;
        $triple_triple_5 = 0;
        $triple_triple_6 = 0;
        $triple_any_triple = 0;
        // ========================================
        // 初始化计算变量 - 组合
        // ========================================
        $combo_combo_1_2 = 0;
        $combo_combo_1_3 = 0;
        $combo_combo_1_4 = 0;
        $combo_combo_1_5 = 0;
        $combo_combo_1_6 = 0;

        $combo_combo_2_3 = 0;
        $combo_combo_2_4 = 0;
        $combo_combo_2_5 = 0;
        $combo_combo_2_6 = 0;

        $combo_combo_3_4 = 0;
        $combo_combo_3_5 = 0;
        $combo_combo_3_6 = 0;

        $combo_combo_4_5 = 0;
        $combo_combo_4_6 = 0;

        $combo_combo_5_6 = 0;


        // ========================================
        // ========================================
        // ========================================
        // 开始计算了
        // ========================================
        // ========================================
        // ========================================

        // ========================================
        // 初始化计算变量 - 基础组
        // ========================================
        if (4<= $total <=10){
            $basic_small = 1;
        }
        if (11<= $total <=17){
            $basic_big = 1;
        }
        if($total % 2 == 1 ){
            $basic_odd = 1;
        }else{
            $basic_even = 1;
        }
        // ========================================
        // 初始化计算变量 - 总和组
        // ========================================
        if($total == 4){
            $total_total_4 = 1;
        }
        if($total == 5){
            $total_total_5 = 1;
        }
        if($total == 6){
            $total_total_6 = 1;
        }
        if($total == 7){
            $total_total_7 = 1;
        }
        if($total == 8){
            $total_total_8 = 1;
        }
        if($total == 9){
            $total_total_9 = 1;
        }
        if($total == 10){
            $total_total_10 = 1;
        }
        if($total == 11){
            $total_total_11 = 1;
        }
        if($total == 12){
            $total_total_12 = 1;
        }
        if($total == 13){
            $total_total_13 = 1;
        }
        if($total == 14){
            $total_total_14 = 1;
        }
        if($total == 15){
            $total_total_415 = 1;
        }
        if($total == 16){
            $total_total_16 = 1;
        }
        if($total == 17){
            $total_total_17 = 1;
        }
        // ========================================
        // 初始化计算变量 - 单个的
        // ========================================
        if($dice1 == 1 || $dice2 == 1 || $dice3 == 1){
            $single_single_1 = 1;
        }
        if($dice1 == 2 || $dice2 == 2 || $dice3 == 2){
            $single_single_2 = 1;
        }
        if($dice1 == 3 || $dice2 == 3 || $dice3 == 3){
            $single_single_3 = 1;
        }
        if($dice1 == 4 || $dice2 == 4 || $dice3 == 4){
            $single_single_4 = 1;
        }
        if($dice1 == 5 || $dice2 == 5 || $dice3 == 5){
            $single_single_5 = 1;
        }
        if($dice1 == 6 || $dice2 == 6 || $dice3 == 6){
            $single_single_6 = 1;
        }
        // 
        if($dice1 ==  $dice2 == 6 && $dice3 == $dice1){
            $triple_any_triple = 1;
        }

        // ========================================
        // 初始化计算变量 - 对子
        // ========================================
        if(($dice1 == 1 && $dice2 == 1) || ($dice1 == 1 && $dice3 == 1) || ($dice2 == 1 && $dice3 == 1)){
            $pair_pair_1 = 1;
        }
        if(($dice1 == 2 && $dice2 == 2) || ($dice1 == 2 && $dice3 == 2) || ($dice2 == 2 && $dice3 == 2)){
            $pair_pair_2 = 1;
        }
        if(($dice1 == 3 && $dice2 == 3) || ($dice1 == 3 && $dice3 == 3) || ($dice2 == 3 && $dice3 == 3)){
            $pair_pair_3 = 1;
        }
        if(($dice1 == 4 && $dice2 == 4) || ($dice1 == 4 && $dice3 == 4) || ($dice2 == 4 && $dice3 == 4)){
            $pair_pair_4 = 1;
        }
        if(($dice1 == 5 && $dice2 == 5) || ($dice1 == 5 && $dice3 == 5) || ($dice2 == 5 && $dice3 == 5)){
            $pair_pair_5 = 1;
        }
        if(($dice1 == 6 && $dice2 == 6) || ($dice1 == 6 && $dice3 == 6) || ($dice2 == 6 && $dice3 == 6)){
            $pair_pair_6 = 1;
        }
        // ========================================
        // 初始化计算变量 - 三同
        // ========================================
        if($dice1 == 1 && $dice2 == 1 && $dice3 == 1){
            $triple_triple_1 = 1;
        }
        if($dice1 == 2 && $dice2 == 2 && $dice3 == 2){
            $triple_triple_2 = 1;
        }
        if($dice1 == 3 && $dice2 == 3 && $dice3 == 3){
            $triple_triple_3 = 1;
        }
        if($dice1 == 4 && $dice2 == 4 && $dice3 == 4){
            $triple_triple_4 = 1;
        }
        if($dice1 == 5 && $dice2 == 5 && $dice3 == 5){
            $triple_triple_5 = 1;
        }
        if($dice1 == 6 && $dice2 == 6 && $dice3 == 6){
            $triple_triple_6 = 1;
        }

        // ========================================
        // 初始化计算变量 - 组合
        // ========================================
        $combo_combo_1_2 = $this->getComboResult($dice1,$dice2,$dice3,1,2);
        $combo_combo_1_3 = $this->getComboResult($dice1,$dice2,$dice3,1,3);
        $combo_combo_1_4 = $this->getComboResult($dice1,$dice2,$dice3,1,4);
        $combo_combo_1_5 = $this->getComboResult($dice1,$dice2,$dice3,1,5);
        $combo_combo_1_6 = $this->getComboResult($dice1,$dice2,$dice3,1,6);

        $combo_combo_2_3 = $this->getComboResult($dice1,$dice2,$dice3,2,3);
        $combo_combo_2_4 = $this->getComboResult($dice1,$dice2,$dice3,2,4);
        $combo_combo_2_5 = $this->getComboResult($dice1,$dice2,$dice3,2,5);
        $combo_combo_2_6 = $this->getComboResult($dice1,$dice2,$dice3,2,6);

        $combo_combo_3_4 = $this->getComboResult($dice1,$dice2,$dice3,3,4);
        $combo_combo_3_5 = $this->getComboResult($dice1,$dice2,$dice3,3,5);
        $combo_combo_3_6 = $this->getComboResult($dice1,$dice2,$dice3,3,6);

        $combo_combo_4_5 = $this->getComboResult($dice1,$dice2,$dice3,4,5);
        $combo_combo_4_6 = $this->getComboResult($dice1,$dice2,$dice3,4,6);

        $combo_combo_5_6 = $this->getComboResult($dice1,$dice2,$dice3,5,6);

        // ========================================
        // ========================================
        // ========================================
        // 计算结束
        // ========================================
        // ========================================
        // ========================================



        // ========================================
        // 返回所有中间计算结果
        // ========================================
        return [
            // ========================================
            // 初始化计算变量 - 基础组
            // ========================================
            'basic_small' => $basic_small,
            'basic_big' => $basic_big,
            'basic_odd' => $basic_odd,
            'basic_even' => $basic_even,
            // ========================================
            // 初始化计算变量 - 总和组
            // ========================================
            'total_total_4' => $total_total_4,
            'total_total_5' => $total_total_5,
            'total_total_6' => $total_total_6,
            'total_total_7' => $total_total_7,
            'total_total_8' => $total_total_8,
            'total_total_9' => $total_total_9,
            'total_total_10' => $total_total_10,
            'total_total_11' => $total_total_11,
            'total_total_12' => $total_total_12,
            'total_total_13' => $total_total_13,
            'total_total_14' => $total_total_14,
            'total_total_15' => $total_total_15,
            'total_total_16' => $total_total_16,
            'total_total_17' => $total_total_17,
            // ========================================
            // 初始化计算变量 - 单个的
            // ========================================
            'single_single_1' => $single_single_1,
            'single_single_2' => $single_single_2,
            'single_single_3' => $single_single_3,
            'single_single_4' => $single_single_4,
            'single_single_5' => $single_single_5,
            'single_single_6' => $single_single_6,
            // ========================================
            // 初始化计算变量 - 对子
            // ========================================
            'pair_pair_1' => $pair_pair_1,
            'pair_pair_2' => $pair_pair_2,
            'pair_pair_3' => $pair_pair_3,
            'pair_pair_4' => $pair_pair_4,
            'pair_pair_5' => $pair_pair_5,
            'pair_pair_6' => $pair_pair_6,
            // ========================================
            // 初始化计算变量 - 三同
            // ========================================
            'triple_triple_1' => $triple_triple_1,
            'triple_triple_2' => $triple_triple_2,
            'triple_triple_3' => $triple_triple_3,
            'triple_triple_4' => $triple_triple_4,
            'triple_triple_5' => $triple_triple_5,
            'triple_triple_6' => $triple_triple_6,
            'triple_any_triple' => $triple_any_triple,
            // ========================================
            // 初始化计算变量 - 组合
            // ========================================
            'combo_combo_1_2' => $combo_combo_1_2,
            'combo_combo_1_3' => $combo_combo_1_3,
            'combo_combo_1_4' => $combo_combo_1_4,
            'combo_combo_1_5' => $combo_combo_1_5,
            'combo_combo_1_6' => $combo_combo_1_6,

            'combo_combo_2_3' => $combo_combo_2_3,
            'combo_combo_2_4' => $combo_combo_2_4,
            'combo_combo_2_5' => $combo_combo_2_5,
            'combo_combo_2_6' => $combo_combo_2_6,

            'combo_combo_3_4' => $combo_combo_3_4,
            'combo_combo_3_5' => $combo_combo_3_5,
            'combo_combo_3_6' => $combo_combo_3_6,

            'combo_combo_4_5' => $combo_combo_4_5,
            'combo_combo_4_6' => $combo_combo_4_6,

            'combo_combo_5_6' => $combo_combo_5_6
        ];
    }

    private function getComboResult($dice1,$dice2,$dice3,$num1,$num2){
        $res = 0;

        if($dice1 == $num1){
            if($dice2 == $num2 || $dice3 == $num2){
                $res = 1;
            }
        }
        if($dice2 == $num1){
            if($dice1 == $num2 || $dice3 == $num2){
                $res = 1;
            }
        }
        if($dice3 == $num11){
            if($dice1 == $num2 || $dice2 == $num2){
                $res = 1;
            }
        }
        if($dice1 == $num2){
            if($dice2 == $num1|| $dice3 == $num1){
                $res = 1;
            }
        }
        if($dice2 == $num2){
            if($dice1 == $num1 || $dice3 == $num1){
                $res = 1;
            }
        }
        if($dice3 == $num2){
            if($dice1 == $num1 || $dice2 == $num1){
                $res = 1;
            }
        }

        return $res;
    }
    /**
     * ========================================
     * 第三步：计算最终游戏结果
     * ========================================
     * 
     * 
     * @param array $res 中间计算结果数组
     * @return array 最终游戏结果数组
     */
    public function calculation_result(array $res): array
    {
           
        $res = Db::name('dianji_game_peilv')->wehre('game_type_id',9)->select()->toArray();
        $win_array = [];   // 主结果：1=庄赢, 2=闲赢, 3=和牌, 0=错误   

        foreach($res as $key => $value){
            if($res[$value['remark']] == 1){
                $win_array[] = $value['id'];
            }
        } 

        // 更新结果到数组中
        $res['win_array'] = $win_array;

        return $res;
    }

    /**
     * ========================================
     * 判断用户投注是否中奖
     * ========================================
     * 
     * 根据用户的投注类型ID和游戏结果，
     * 判断该笔投注是否中奖
     * 
     * @param int $resId 用户购买的投注类型ID
     * @param array $paiInfo 游戏计算结果
     * @return bool true=中奖, false=未中奖
     */
    public function user_win_or_not(int $resId, array $paiInfo): bool
    {
   
        // 判断用户投注信息 是否在 中将池子里面
        return in_array($resId, $paiInfo['win_array']); 


    }

    /**
     * ========================================
     * 将投注类型ID转换为中文描述
     * ========================================
     * 
     * @param int $res 投注类型ID
     * @return string 中文描述
     */
    public function user_pai_chinese(int $res): string
    {
        $res = Db::name('dianji_game_peilv')->wehre('game_type_id',9)->select()->toArray();
        $pai_names = [];
        foreach($res as $key => $value){
            $pai_names[$value['id']] = $value['game_tip_name']
        }       
        return $pai_names[$res] ?? '未知';
    }

    /**
     * ========================================
     * 将游戏结果转换为中文描述字符串
     * ========================================
     * 
     * @param array $paiInfo 游戏计算结果
     * @return string 中文结果描述，用"|"分隔
     */
    public function pai_chinese(array $paiInfo): string
    {
        $string = '';
        foreach ($paiInfo['win_array'] as $win) {
            $string .= $this->user_pai_chinese($win).'|';
        }
        return $string;
    }


    /**
     * ========================================
     * 生成需要闪烁显示的投注区域ID数组
     * ========================================
     * 
     * 根据游戏结果，返回前端需要高亮闪烁的
     * 投注区域ID数组，用于视觉效果展示
     * 
     * @param array $paiInfo 游戏计算结果
     * @return array 需要闪烁的投注区域ID数组
     */
    public function pai_flash(array $paiInfo): array
    {
        $map = [];
        
        foreach ($paiInfo['win_array'] as $win) {
            $map[] = $win;
        }

        return $map;

    }
}

/**
 * ========================================
 * 类使用说明和业务规则
 * ========================================
 * 
 * 1. 核心业务流程：
 *    原始数据 -> calculation() -> calculation_start() -> calculation_result()
 * 
 * 2. 骰宝规则要点：
 *    - 所有牌值大于9按0计算（J,Q,K,10都是0点）
 *    - 总点数超过9取余数（如15点=5点）
 *    - 庄闲点数相等为和牌
 *    - 幸运6：庄赢且庄家点数为6点
 * 
 * 3. 特殊投注规则：
 *    - 大小：根据0点牌的数量判断
 *    - 对子：同位置前两张牌牌值相同
 *    - 幸运6：特殊奖励投注，赔率较高
 * 
 * 4. 数据结构说明：
 *    - 位置1,2,3：庄家的牌
 *    - 位置4,5,6：闲家的牌
 *    - 花色：h=黑桃, r=红桃, f=方块, m=梅花
 * 
 * 5. 性能优化：
 *    - 单次计算完成所有结果
 *    - 避免重复数据处理
 *    - 结构化的数据返回格式
 */