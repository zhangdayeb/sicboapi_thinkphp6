<?php

namespace app\business;

class RequestUrl
{
    public static function user_url():string
    {
        return '/user/user/index';
    }
    public static function conf_url():string
    {
        return '/conf/info';
    }
}