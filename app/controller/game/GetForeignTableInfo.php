<?php

namespace app\controller\game;

use app\controller\common\LogHelper;
use app\BaseController;
use app\model\Luzhu;
use app\model\LuzhuHeguan;
use app\model\LuzhuPreset;
use app\model\Table;
use app\job\TableEndTaskJob;
use app\service\CardSettlementService;
use app\validate\BetOrder as validates;
use think\exception\ValidateException;
use think\facade\Queue;

class GetForeignTableInfo extends BaseController
{
    
    
    public function testluzhu(){
    
        $params = $this->request->param();
       
        $returnData = LuzhuHeguan::LuZhutest($params);
        show($returnData, 1);
    }
    
    public function get_table_video()
    {
        $params = $this->request->param();
        $returnData = array();
        $info = Table::order('id desc')->find($params['tableId']);
        $returnData['video_near'] = $info['video_near'];
        $returnData['video_far'] = $info['video_far'];
        // 返回数据
        show($returnData, 1);
    }

    //获取荷官台桌露珠信息
    public function get_hg_lz_list(): string
    {
        
        $params = $this->request->param();
       
        $returnData = LuzhuHeguan::LuZhuList($params);
        show($returnData, 1);
    }

    public function get_hg_data_list(): string
    {
        
        $params = $this->request->param();
       //查询台桌是否在洗桌状态
       //获取台桌信息
        if(!isset($params['tableId']) || empty($params['tableId'])) return show([],1,'台桌ID不存在');
         $table  = Table::where('id',$params['tableId'])->find();
         if(empty($table)) return show([],1,'台桌不存在');
         $table = $table->toArray();
         if($table['wash_status']  ==1 ){
               show([], 1);
         }
        $returnData = LuzhuHeguan::LuZhuList($params);
        show($returnData, 1);
    }
    
    public function get_hg_video_list(): string
    {
        
        $params = $this->request->param();
        //获取台桌信息
        if(!isset($params['tableId']) || empty($params['tableId'])) return show([],1,'台桌ID不存在');
        $table  = Table::where('id',$params['tableId'])->find();
         if(empty($table)) return show([],1,'台桌不存在');
         $table = $table->toArray();
       $video_near = explode('=',$table['video_near']);
       $video_far = explode('=',$table['video_far']);

        show(['video_near'=>$video_near[1].$table['id'],'video_far'=>$video_far[1].$table['id']], 1);
    }

   //获取台桌露珠信息
    public function get_lz_list(): string
    {
        $params = $this->request->param();
        $tableId = $this->request->param('tableId',0);
        if ($tableId <=0 ) show([], config('ToConfig.http_code.error'),'台桌ID必填');
        $returnData = Luzhu::LuZhuList($params);        
        show($returnData, 1);
    }

    // 获取台桌列表
    public function get_table_list(): string
    {
        $gameTypeView = array(
            '1' => '_nn',
            '2' => '_lh',
            '3' => '_bjl'
        );
        $infos = Table::where(['status' => 1])->order('id asc')->select()->toArray();
        empty($infos) && show($infos, 1);
        foreach ($infos as $k => $v) {
            // 设置台桌类型 对应的 view 文件
            $infos[$k]['viewType'] = $gameTypeView[$v['game_type']];
            $number = rand(100, 3000);// 随机人数
            $infos[$k]['number'] = $number;
            // 获取靴号
            //正式需要加上时间查询
            $luZhu = Luzhu::where(['status' => 1, 'table_id' => $v['id']])->whereTime('create_time', 'today')->select()->toArray();

            if (isset($luZhu['xue_number'])) {
                $infos[$k]['xue_number'] = $luZhu['xue_number'];
                continue;
            }
            $infos[$k]['xue_number'] = 1;
        }
        show($infos, 1);
    }

    // 获取统计数据
    public function get_table_count(): string
    {
        $params = $this->request->param();
        $map = array();
        $map['status'] = 1;
        if (!isset($params['tableId']) || !isset($params['xue']) || !isset($params['gameType'])) {
            show([], 0,'');
        }

        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['xue'];
        $map['game_type'] = $params['gameType']; // 代表百家乐

        $nowTime = time();
		$startTime = strtotime(date("Y-m-d 09:00:00", time()));
		// 如果小于，则算前一天的
		if ($nowTime < $startTime) {
		    $startTime = $startTime - (24 * 60 * 60);
		} else {
		    // 保持不变 这样做到 自动更新 露珠
		}

        // 需要兼容 龙7 熊8 大小老虎 69 幸运6 
        $returnData = array();
        $returnData_zhuang_1 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '1|%')->where($map)->order('id asc')->count();
        $returnData_zhuang_4 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '4|%')->where($map)->order('id asc')->count();
        $returnData_zhuang_6 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '6|%')->where($map)->order('id asc')->count();
        $returnData_zhuang_7 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '7|%')->where($map)->order('id asc')->count();
        $returnData_zhuang_9 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '9|%')->where($map)->order('id asc')->count();
		$returnData['zhuang'] = $returnData_zhuang_1 + $returnData_zhuang_4 + $returnData_zhuang_6 + $returnData_zhuang_7 + $returnData_zhuang_9;

        $returnData_xian_2 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '2|%')->where($map)->order('id asc')->count();
        $returnData_xian_8 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '8|%')->where($map)->order('id asc')->count();
        $returnData['xian'] = $returnData_xian_2 + $returnData_xian_8;

        $returnData['he'] = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '3|%')->where($map)->order('id asc')->count();
        $returnData['zhuangDui'] = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '%|1')->where($map)->order('id asc')->count();
        $returnData['xianDui'] = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '%|2')->where($map)->order('id asc')->count();
        $returnData['zhuangXianDui'] = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '%|3')->where($map)->order('id asc')->count();
        $returnData['zhuangDui'] += $returnData['zhuangXianDui'];
        $returnData['xianDui'] += $returnData['zhuangXianDui'];
        // 返回数据
        show($returnData, 1);
    }

    //获取发送的数据 荷官开牌
    public function set_post_data(): string
    {
        LogHelper::debug('=== 开牌流程开始 ===');
        LogHelper::debug('接收到荷官开牌请求', [
            'ip' => request()->ip(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);


        $postField = 'gameType,tableId,xueNumber,puNumber,result,ext,pai_result';
        $params = $this->request->only(explode(',', $postField), 'param', null);
        LogHelper::debug('荷官原始参数', $params);

        try {
            validate(validates::class)->scene('lz_post')->check($params);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }
        $map = array();
        $map['status'] = 1;
        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['xueNumber'];
        $map['pu_number'] = $params['puNumber'];
        $map['game_type'] = $params['gameType'];

        //查询当日最新的一铺牌
        $info = Luzhu::whereTime('create_time', 'today')->where('result','<>',0)->where($map)->find();
        if (!empty($info)) show($info, 0, '数据重复上传');

        #####开始预设###########
        //查询是否有预设的开牌信息
        $presetInfo = LuzhuPreset::LuZhuPresetFind($map);
        $map['update_time'] = $map['create_time'] = time();
        $HeguanLuzhu = $map;

        $id = 0;
        if ($presetInfo){
            //插入当前信息
            $id = $presetInfo['id'];
            $map['result'] = $presetInfo['result'];
            $map['result_pai'] = $presetInfo['result_pai'];
        }else{
            //插入当前信息
            $map['result'] = intval($params['result']) . '|' . intval($params['ext']);
            $map['result_pai'] = json_encode($params['pai_result']);
        }
        //荷官正常露珠
        $HeguanLuzhu['result'] = intval($params['result']) . '|' . intval($params['ext']);
        $HeguanLuzhu['result_pai'] = json_encode($params['pai_result']);
        #####结束预设###########


        // 增加缓存删除
        \think\facade\Cache::delete('luzhuinfo_'.$params['tableId']);

        // 根据游戏类型调用相应服务
        switch ($map['game_type']) {
            case 3:
                LogHelper::debug('调用百家乐开牌服务', ['table_id' => $map['table_id']]);
                $card = new CardSettlementService();
                return $card->open_game($map, $HeguanLuzhu, $id);
            default:
                LogHelper::error('不支持的游戏类型', ['game_type' => $map['game_type']]);
                show([], 404, 'game_type错误！');
        }
    }
    
    
    //测试 露珠缓存
    public function set_post_data_test(): string
    {
        $postField = 'gameType,tableId,xueNumber,puNumber,result,ext,pai_result';
        $params = $this->request->only(explode(',', $postField), 'param', null);
        
       
     

        try {
            validate(validates::class)->scene('lz_post')->check($params);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }
        $map = array();
        $map['status'] = 1;
        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['xueNumber'];
        $map['pu_number'] = $params['puNumber'];
        $map['game_type'] = $params['gameType'];

        //查询当日最新的一铺牌
        $info = Luzhu::whereTime('create_time', 'today')->where('result','<>',0)->where($map)->find();
        if (!empty($info)) show($info, 0, '数据重复上传');

        #####开始预设###########
        //查询是否有预设的开牌信息
        $presetInfo = LuzhuPreset::LuZhuPresetFind($map);
        $map['update_time'] = $map['create_time'] = time();
        $HeguanLuzhu = $map;

        $id = 0;
        if ($presetInfo){
            //插入当前信息
            $id = $presetInfo['id'];
            $map['result'] = $presetInfo['result'];
            $map['result_pai'] = $presetInfo['result_pai'];
        }else{
            //插入当前信息
            $map['result'] = intval($params['result']) . '|' . intval($params['ext']);
            $map['result_pai'] = json_encode($params['pai_result']);
        }
        //荷官正常露珠
        $HeguanLuzhu['result'] = intval($params['result']) . '|' . intval($params['ext']);
        $HeguanLuzhu['result_pai'] = json_encode($params['pai_result']);
        #####结束预设###########

        switch ($map['game_type']){
            case 3:
                //龙虎开牌
                $card = new CardSettlementService();
                return $card->open_game($map,$HeguanLuzhu,$id);
            default:
                show([],404,'game_type错误！');
        }
    }
    
    //删除指定露珠
    public function lz_delete(): string
    {
        $params = $this->request->param();
        $map = array();
        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['num_xue'];
        $map['pu_number'] = $params['num_pu'];
        Luzhu::where($map)->delete();
        show([], config('ToConfig.http_code.error'));
    }

    //清除一张台桌露珠
    public function lz_table_delete(): string
    {
        $table_id = $this->request->param('tableId', 0);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'), '台桌id错误');
        $del = Luzhu::where(['table_id' => $table_id])->delete();
        if ($del) show([]);
        show([], config('ToConfig.http_code.error'));
    }

    //设置靴号 荷官主动换靴号
    public function set_xue_number(): string
    {
        //过滤数据
        $postField = 'tableId,num_xue,gameType';
        $post = $this->request->only(explode(',', $postField), 'param', null);

        try {
            validate(validates::class)->scene('lz_set_xue')->check($post);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }
        // 查询当前最新的靴号
        //主动换靴号，获取最新的靴号 +1；铺号为 0
        // 缺少时间
        $nowTime = time();
        $startTime = strtotime(date("Y-m-d 04:00:00", time()));
        // 如果小于，则算前一天的
//        if ($nowTime < $startTime) {
//            $startTime = $startTime - (24 * 60 * 60);
//        } else {
//            // 保持不变 这样做到 自动更新 露珠
//        }
        //取才创建时间最后一条数据
        $find = Luzhu::where('table_id', $post['tableId'])->whereTime('create_time', 'today')->order('id desc')->find();

        if ($find) {
            $xue_number['xue_number'] = $find->xue_number + 1;
        } else {
            $xue_number['xue_number'] = 1;
        }
        $post['status'] = 1;
        $post['table_id'] = $post['tableId'];
        $post['xue_number'] = $xue_number['xue_number'];
        $post['pu_number'] = 1;
        $post['update_time'] = $post['create_time'] = time();
        $post['game_type'] = $post['gameType'];
        $post['result'] = 0;
        $post['result_pai'] = 0;

        $save = (new Luzhu())->save($post);
        if ($save) show($post);
        show($post, config('ToConfig.http_code.error'));
    }

    //开局信号
    public function set_start_signal(): string
    {
        $table_id = $this->request->param('tableId', 0);
        $time = $this->request->param('time', 45);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'), 'tableId参数错误');
        $data = [
            'start_time' => time(),
            'countdown_time' => $time,
            'status' => 1,
            'run_status' => 1,
            'wash_status'=>0,
            'update_time' => time(),
        ];
        $save = Table::where('id', $table_id)
            ->update($data);
        if (!$save) {
            show($data, config('ToConfig.http_code.error'));
        }
        $data['table_id'] = $table_id;
        Queue::later($time, TableEndTaskJob::class, $data,'bjl_end_queue');
        redis()->del('table_info_'.$table_id);
        redis()->set('table_set_start_signal_'.$table_id,$table_id,$time+5);//储存redis
        show($data);
    }

    //结束信号
    public function set_end_signal(): string
    {
        $table_id = $this->request->param('tableId', 0);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'));
        $save = Table::where('id', $table_id)
            ->update([
                'status' => 1,
                'run_status' => 2,
                'wash_status'=>0,
                'update_time' => time(),
            ]);
        if ($save) show([]);
        show([], config('ToConfig.http_code.error'));
    }

    //台桌信息 靴号 铺号
    public function get_table_info()
    {
        $params = $this->request->param();
        $returnData = array();
        $info = Table::order('id desc')->find($params['tableId']);
        // 发给前台的 数据
        $returnData['lu_zhu_name'] = $info['lu_zhu_name'];
        $returnData['right_money_banker_player'] = $info['xian_hong_zhuang_xian_usd'];
        $returnData['right_money_banker_player_cny'] = $info['xian_hong_zhuang_xian_cny'];
        $returnData['right_money_tie'] = $info['xian_hong_he_usd'];
        $returnData['right_money_tie_cny'] = $info['xian_hong_he_cny'];
        $returnData['right_money_pair'] = $info['xian_hong_duizi_usd'];
        $returnData['right_money_pair_cny'] = $info['xian_hong_duizi_cny'];
        $returnData['video_near'] = $info['video_near'];
        $returnData['video_far'] = $info['video_far'];
        $returnData['time_start'] = $info['countdown_time'];

        // 获取最新的 靴号，铺号
        $map = array();
        //$map['status'] = 1;
        $map['table_id'] = $params['tableId'];
        $map['game_type'] = $params['gameType'];

        $nowTime = time();
        $startTime = strtotime(date("Y-m-d 04:00:00", time()));
        // 如果小于，则算前一天的
//        if ($nowTime < $startTime) {
//            $startTime = $startTime - (24 * 60 * 60);
//        } else {
//            // 保持不变 这样做到 自动更新 露珠
//        }

        $xun = bureau_number($params['tableId'],true);

        //$xueData = Luzhu::whereTime('create_time', 'today')->where($map)->order('id desc')->find();
//        dump(date("Y-m-d H:i:s",$startTime));
//        dump(Db::name('diantouGameLuZhu')->fetchSql()->where('create_time','>',$startTime)->where($map)->order('id desc')->find());


        //$returnData['num_xue'] = isset($xueData['xue_number']) ? $xueData['xue_number'] : 1;
//        if ($xueData['result'] == '0') {
//            $returnData['num_pu'] = 1;
//        } else {
//            $returnData['num_pu'] = ($xueData['result'] == '0|0') ? 1 : ($xueData['pu_number'] + 1);
//        }

        $returnData['id'] = $info['id'];
        $returnData['num_pu'] = $xun['xue']['pu_number'];
        $returnData['num_xue'] = $xun['xue']['xue_number'];
        $returnData['result_info']  = ['table_info'=>['game_type'=>123456]];
        $returnData['money_spend']  = '';
        worker_tcp('userall','洗牌中！',$returnData,207);
        unset($returnData['result_info']);
        unset($returnData['money_spend']);
        // 返回数据
        show($returnData, 1);

    }
    //台桌信息
    public function get_table_wash_brand()
    {
        $tableId = $this->request->param('tableId',0);
        if ($tableId <=0 ) show([], config('ToConfig.http_code.error'),'台桌ID必填');
        $table  = Table::where('id',$tableId)->find();
        $status = $table->wash_status == 0 ? 1 : 0;
        $table->save(['wash_status'=>$status]);
        $returnData['result_info']  = ['table_info'=>['game_type'=>123456]];
        $returnData['money_spend']  = '';
         worker_tcp('userall','洗牌中！',$returnData,207);
        // 返回数据
        show([], 1);
    }

}