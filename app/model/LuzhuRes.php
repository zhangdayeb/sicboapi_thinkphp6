<?php

namespace app\model;
use app\controller\common\LogHelper;
use think\Model;

class LuzhuRes extends Model
{

    public static function LuZhuList($params){
        $map = array();
        $map['status'] = 1;
        $map['table_id'] = $params['tableId'];
        if (isset($params['xue']) && $params['xue'] > 0) $map['xue_number'] = $params['xue'];
       
        // 增加靴号的重新处理
        if(!isset($map['xue_number'])){
            $one_info = self::where($map)->order('id desc')->find();
            !empty($one_info) &&  $map['xue_number'] = $one_info->xue_number;
        }

        $map['game_type'] = isset($params['gameType']) && !empty($params['gameType']) ? $params['gameType'] : 3; // 代表骰宝 | 龙虎
        $limit = 66;
        $returnData = array();

        $date = date('Y-m-d');
        if(time() > strtotime(date('Y-m-d'.' 09:00:00'))){
            $date = date('Y-m-d'.' 09:00:00');
        }
        // LogHelper::debug('=== 查询条件 ===',$map);
        $info = self::whereTime('create_time','>', $date)->where('result','<>',0)->where($map)->order('id asc')->limit($limit)->select();
     
        // 发给前台的 数据
        // LogHelper::debug('=== 查询条件 ===',json_encode($info));
        $i = 0;
        foreach ($info as $k => $val) {
            $k = 'k' . $i;
            $returnData[$k] = $val['result_pai'];
            $i++;
        }
        
        return $returnData;
    }
    
    public static function LuZhutest($params){
        $map = array();
        $map['status'] = 1;
        $map['table_id'] = $params['tableId'];
        if (isset($params['xue']) && $params['xue'] > 0) $map['xue_number'] = $params['xue'];

        // 增加靴号的重新处理
         //if()
        if(!isset($map['xue_number'])){
            $one_info = self::where($map)->whereTime('create_time', 'today')->order('id desc')->find();
            !empty($one_info) &&  $map['xue_number'] = $one_info->xue_number;
        }

        $map['game_type'] = isset($params['gameType']) && !empty($params['gameType']) ? $params['gameType'] : 3; // 代表骰宝 | 龙虎
        $limit = 66;
        if($map['game_type'] == 2){
            $limit = 180;
        }
        $returnData = array();
        
        $date = date('Y-m-d');
        if(time() > strtotime(date('Y-m-d'.' 09:00:00'))){
            $date = date('Y-m-d'.' 09:00:00');
        }

        $info = self::whereTime('create_time','>', $date)
        ->where('result','<>',0)
        ->where($map)
        ->cache('luzhuinfo_'.$map['table_id'],60)
        ->order('id asc')
        ->limit($limit)
        ->select();

        // 发给前台的 数据
        $i = 0;
        foreach ($info as $k => $val) {
            $k = 'k' . $i;
            $returnData[$k] = $val['result_pai'];
            $i++;
        }
        return $returnData;
    }
}