<?php

namespace app\business;

class RequestUrl
{
    // 获取用户语言包
    public static function user_url():string
    {
        return '/user/user/index';
    }

    // 获取用户限红配置
    public static function conf_url():string
    {
        return '/conf/info';
    }
}