<?php

namespace app\controller\order;

use app\controller\common\LogHelper;
use app\business\Curl;
use app\business\RequestUrl;
use app\controller\Base;
use app\model\GameRecords;

class OrderBase extends Base
{

    //获取配置文件
    public function get_config(string $name)
    {
        $url = env('curl.http', '0.0.0.0') . RequestUrl::conf_url();
        $data = Curl::post($url, ['name' => $name]);
        if ($data['code'] != 200) return show([], 200, $data['message']);
        return $data['data'];
    }

    //用户限红
    public function user_xian_hong($table_id, $value, $xue_number, $odds, $table_info)
    {
        // 限红太多了 用赔率表里面的那个限红设置
        $money = $value['money'];
        $tip = '超过限红设置';

        //赔率限红
        if (empty($odds)) return show([], config('ToConfig.http_code.error'), 'please fill in the correct odds id');
        if ($value['money'] < $odds->xian_hong_min) show([], config('ToConfig.http_code.error'), lang('limit red minimum least') . ':' . $odds->xian_hong_min);
        if ($money > $odds->xian_hong_max) show([], config('ToConfig.http_code.error'), lang('limit red maximum most') . ':' . $odds->xian_hong_max);
        return true;
    }

    //当前下单记录
    public function order_current_record()
    {
        $table_id = $this->request->post('id/d', 0);
        if ($table_id <= 0) show([]);

        $records = GameRecords::where([
                'user_id'      => self::$user['id'],
                'table_id'     => $table_id,
                'close_status' => 1,
            ])
            ->field('bet_amt,game_peilv_id,is_exempt')
            ->whereTime('created_at', '-10 minutes')
            ->select();

        $is_exempt = 0;

        foreach ($records as $record) {
            if ($record['is_exempt'] != 0) {
                $is_exempt = $record['is_exempt'];
                break; // 一旦发现免佣，提前结束
            }
        }

        show([
            'is_exempt'   => $is_exempt,
            'record_list' => $records,
        ]);
    }

}