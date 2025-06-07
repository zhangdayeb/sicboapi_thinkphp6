<?php
/**
 * 调试控制器 - 用于诊断路由和系统问题
 */
namespace app\controller;

use think\facade\Db;
use think\facade\Cache;

class Debug
{
    /**
     * 系统信息页面
     */
    public function index()
    {
        $info = [
            'php_version' => PHP_VERSION,
            'thinkphp_version' => \think\App::VERSION,
            'current_time' => date('Y-m-d H:i:s'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
            'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB'
        ];

        // 检查数据库
        try {
            Db::query('SELECT 1');
            $info['database_status'] = '连接正常';
        } catch (\Exception $e) {
            $info['database_status'] = '连接失败: ' . $e->getMessage();
        }

        // 检查缓存
        try {
            Cache::set('debug_test', time(), 10);
            $info['cache_status'] = Cache::get('debug_test') ? '正常' : '异常';
        } catch (\Exception $e) {
            $info['cache_status'] = '异常: ' . $e->getMessage();
        }

        // 检查控制器类
        $controllers = [
            'Index' => 'app\\controller\\Index',
            'Debug' => 'app\\controller\\Debug'
        ];
        
        $info['controllers'] = [];
        foreach ($controllers as $name => $class) {
            $info['controllers'][$name] = [
                'exists' => class_exists($class),
                'methods' => class_exists($class) ? get_class_methods($class) : []
            ];
        }

        return json([
            'success' => true,
            'system_info' => $info,
            'timestamp' => time()
        ]);
    }

    /**
     * 测试健康检查
     */
    public function health()
    {
        try {
            // 数据库检查
            $dbOk = false;
            try {
                Db::query('SELECT 1');
                $dbOk = true;
            } catch (\Exception $e) {
                // 数据库连接失败
            }

            // 缓存检查
            $cacheOk = false;
            try {
                Cache::set('health_test', time(), 10);
                $cacheOk = Cache::get('health_test') !== null;
            } catch (\Exception $e) {
                // 缓存失败
            }

            // 文件系统检查
            $fsOk = is_writable(runtime_path());

            $checks = [
                'database' => $dbOk,
                'cache' => $cacheOk,
                'filesystem' => $fsOk,
                'php' => version_compare(PHP_VERSION, '7.1.0', '>=')
            ];

            $allOk = !in_array(false, $checks);

            return json([
                'status' => $allOk ? 'healthy' : 'unhealthy',
                'checks' => $checks,
                'timestamp' => time(),
                'message' => $allOk ? '系统运行正常' : '系统存在问题'
            ]);

        } catch (\Exception $e) {
            return json([
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => time()
            ]);
        }
    }

    /**
     * 测试简单的JSON响应
     */
    public function test()
    {
        return json([
            'success' => true,
            'message' => '测试成功',
            'data' => [
                'controller' => 'Debug',
                'action' => 'test',
                'time' => date('Y-m-d H:i:s')
            ]
        ]);
    }

    /**
     * 路由列表（仅调试模式）
     */
    public function routes()
    {
        if (!app()->isDebug()) {
            return json(['error' => '仅在调试模式下可用']);
        }

        try {
            $routes = \think\facade\Route::getRuleList();
            return json([
                'total' => count($routes),
                'routes' => array_slice($routes, 0, 50) // 只显示前50个
            ]);
        } catch (\Exception $e) {
            return json(['error' => $e->getMessage()]);
        }
    }
}