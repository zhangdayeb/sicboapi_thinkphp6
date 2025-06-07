<?php
/**
 * 首页控制器
 * 处理主页面显示和WebSocket通信测试
 */
namespace app\controller;

use app\controller\common\LogHelper;
use app\BaseController;
use think\facade\Log;
use think\facade\View;

class Index extends BaseController
{
    public function index()
    {
        LogHelper::debug('LogHelper调试信息', ['test' => 'data']);        
        return View::fetch();
    }
    
}
