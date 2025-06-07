<?php

function worker_tcp($uid, $mag = '', array $data=[], $code = 202)
{
    $client = stream_socket_client(env('worker.two_tcp', 'websocket://0.0.0.0:3003'), $errno, $errmsg, 1);
    // 推送的数据，包含uid字段，表示是给这个uid推送
    $data = array('code' => $code, 'user_id' => $uid, 'data' => $data, 'msg'=>$mag);
    // 发送数据，注意5678端口是Text协议的端口，Text协议需要在数据末尾加上换行符
    fwrite($client, json_encode($data) . "\n");
    // 读取推送结果
    //echo fread($client, 8192);
}

function redis()
{
    return think\facade\Cache::store('redis');
}

/**
 * @param string $type 类型
 * @param string $name 集合名称
 * @param int $key 集合key
 * @param int $val 集合sort
 */
function redis_sort_set(string $type = 'set', string $name = '', int $key = 0, int $val = 0)
{
    if ($type == 'set') {//存入 有序集合
        return redis()->ZADD($name, $key, $val);
    }
    if ($type == 'get') {//获取有序集合指定的值
        return redis()->ZSCORE($name, $key);
    }

    if ($type == 'del') {//删除有序结合指定的key
        return redis()->ZREM($name, $key);
    }

}

//生成台桌局号
function bureau_number($table_id, $xue_number = false)
{
    $xue = xue_number($table_id);
    $table_bureau_number = date('YmdH') . $table_id . $xue['xue_number'] . $xue['pu_number'];
    if ($xue_number) return ['bureau_number' => $table_bureau_number, 'xue' => $xue];
    return $table_bureau_number;
}

//$table_id 台桌ID
function xue_number($table_id)
{
    //取才创建时间最后一条数据
    $find = \app\model\Luzhu::where('table_id', $table_id)->where('status', 1)->whereTime('create_time', 'today')->order('id desc')->find();
    if (empty($find)) return ['xue_number' => 1, 'pu_number' => 1];
    $xue = $find->xue_number;
    if ($find->result == 0) {
        $pu = $find->pu_number;
    } else {
        $pu = $find->pu_number + 1;
    }
    return ['xue_number' => $xue, 'pu_number' => $pu];
}

function show($data = [], int $code = 200, string $message = 'ok！', int $httpStatus = 0)
{
    $result = [
        'code' => $code,
        'message' => lang($message),
        'data' => $data,
    ];
    header('Access-Control-Allow-Origin:*');
    if ($httpStatus != 0) {
        return json($result, $httpStatus);
    }
    echo json_encode($result);
    exit();
}

function get_config($name = null)
{
    if ($name == null) {
        return \app\model\SysConfig::select();
    }
    return \app\model\SysConfig::where('name', $name)->find();
}

