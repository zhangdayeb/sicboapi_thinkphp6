<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\model\Luzhu;
use app\controller\Base;
use app\service\WorkerOpenPaiService;

class GameInfo extends Base
{
    //获取普配牌型，用于前端展示当前牌型
    public function get_poker_type()
    {
        $id = $this->request->post('id/d', 0);
        if ($id <= 0) show([], config('ToConfig.http_code.error'), '露珠ID必填');
        $find = Luzhu::find($id);
        if (empty($find)) show([], config('ToConfig.http_code.error'), '牌型信息不存在');
        if ($find->game_type != 3) show([], config('ToConfig.http_code.error'), '百家乐游戏类型不正确');
        //获取台桌开牌信息
        $service = new WorkerOpenPaiService();
        $poker = $service->get_pai_info_bjl($find->result_pai);
        show($poker);
    }
}