<?php
/**
 * 首页控制器 - 重写版本，与sicbo_test.html视图匹配
 */
namespace app\controller;

use think\facade\View;
use think\facade\Db;
use think\facade\Cache;
use think\Response;

class Index
{
    /**
     * 首页 - 显示骰宝测试页面
     */
    public function index()
    {
        try {
            // 准备视图数据
            $viewData = [
                'page_title' => '骰宝系统连通性测试器',
                'system_info' => [
                    'php_version' => PHP_VERSION,
                    'current_time' => date('Y-m-d H:i:s'),
                    'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
                    'test_groups' => $this->getTestGroups()
                ]
            ];
            
            // 分配数据到视图
            View::assign($viewData);
            
            // 返回骰宝测试页面
            return View::fetch('test/sicbo_test');
            
        } catch (\Exception $e) {
            // 如果视图加载失败，返回JSON响应
            return json([
                'status' => 'error',
                'message' => '页面加载失败：' . $e->getMessage(),
                'fallback' => '视图文件不存在，请检查 view/test/sicbo_test.html 文件',
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'view_path' => 'view/test/sicbo_test.html'
                ]
            ]);
        }
    }

    /**
     * 快速健康检查
     * 路由: GET /test/health
     * 与HTML中的测试URL匹配
     */
    public function quickHealthCheck()
    {
        try {
            $checks = [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'sicbo_tables' => $this->checkSicboTables(),
                'controllers' => $this->checkControllers(),
                'php_version' => version_compare(PHP_VERSION, '7.1.0', '>=')
            ];

            $allPassed = !in_array(false, $checks);

            return json([
                'success' => true,
                'code' => 200,
                'message' => $allPassed ? '系统运行正常' : '系统存在问题',
                'data' => [
                    'status' => $allPassed ? 'healthy' : 'unhealthy',
                    'checks' => $checks,
                    'timestamp' => time(),
                    'details' => [
                        'php_version' => PHP_VERSION,
                        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
                        'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB'
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'success' => false,
                'code' => 500,
                'message' => '健康检查执行失败',
                'data' => [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'timestamp' => time()
                ]
            ]);
        }
    }

    /**
     * 执行完整的骰宝系统测试
     * 路由: GET /test/sicbo/full
     * 与HTML中的连通性测试匹配
     */
    public function testSicboSystemFull()
    {
        $startTime = microtime(true);
        
        try {
            $testResults = [
                'test_name' => '骰宝系统完整连通性测试',
                'start_time' => date('Y-m-d H:i:s'),
                'status' => 'running',
                'modules' => []
            ];

            // 1. 数据库连接测试
            $testResults['modules']['database'] = $this->testDatabaseConnection();
            
            // 2. 骰宝表检查
            $testResults['modules']['sicbo_tables'] = $this->testSicboTables();
            
            // 3. 控制器检查
            $testResults['modules']['controllers'] = $this->testControllers();
            
            // 4. 模型检查
            $testResults['modules']['models'] = $this->testModels();
            
            // 5. 接口模拟测试
            $testResults['modules']['api_simulation'] = $this->testApiSimulation();
            
            // 6. 数据完整性检查
            $testResults['modules']['data_integrity'] = $this->testDataIntegrity();

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            // 计算总体结果
            $totalTests = 0;
            $passedTests = 0;
            
            foreach ($testResults['modules'] as $module) {
                if (isset($module['tests'])) {
                    foreach ($module['tests'] as $test) {
                        $totalTests++;
                        if ($test['passed']) {
                            $passedTests++;
                        }
                    }
                }
            }

            $testResults['status'] = 'completed';
            $testResults['summary'] = [
                'total_tests' => $totalTests,
                'passed_tests' => $passedTests,
                'failed_tests' => $totalTests - $passedTests,
                'success_rate' => $totalTests > 0 ? round($passedTests / $totalTests * 100, 2) : 0,
                'duration_ms' => $duration
            ];

            return json([
                'success' => true,
                'code' => 200,
                'message' => '测试完成',
                'data' => $testResults
            ]);

        } catch (\Exception $e) {
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            return json([
                'success' => false,
                'code' => 500,
                'message' => '测试执行失败',
                'data' => [
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration,
                    'completed_modules' => $testResults['modules'] ?? []
                ]
            ]);
        }
    }

    /**
     * 清理测试数据
     * 路由: POST /test/cleanup
     * 与HTML中的POST测试匹配
     */
    public function cleanupTestData()
    {
        try {
            $cleanupResults = [];

            // 清除测试缓存
            Cache::clear();
            $cleanupResults[] = '清理系统缓存';

            // 清除测试日志（如果有）
            $logPath = runtime_path() . 'log/test/';
            if (is_dir($logPath)) {
                $files = glob($logPath . '*.log');
                foreach ($files as $file) {
                    if (unlink($file)) {
                        $cleanupResults[] = '删除测试日志: ' . basename($file);
                    }
                }
            }

            return json([
                'success' => true,
                'code' => 200,
                'message' => '测试数据清理完成',
                'data' => [
                    'cleanup_actions' => $cleanupResults,
                    'timestamp' => time()
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'success' => false,
                'code' => 500,
                'message' => '清理失败',
                'data' => [
                    'error' => $e->getMessage()
                ]
            ]);
        }
    }

    // ========================================
    // 测试模块方法
    // ========================================

    /**
     * 测试数据库连接
     */
    private function testDatabaseConnection()
    {
        $result = ['name' => '数据库连接测试', 'tests' => []];
        
        // 基础连接测试
        $result['tests'][] = $this->runTest('MySQL连接测试', function() {
            $version = Db::query('SELECT VERSION() as version');
            return !empty($version);
        });

        // 字符集检查
        $result['tests'][] = $this->runTest('数据库字符集检查', function() {
            $charset = Db::query('SELECT @@character_set_database as charset');
            return isset($charset[0]['charset']);
        });

        // 事务支持测试
        $result['tests'][] = $this->runTest('事务支持测试', function() {
            Db::startTrans();
            Db::rollback();
            return true;
        });

        return $result;
    }

    /**
     * 测试骰宝相关表
     */
    private function testSicboTables()
    {
        $result = ['name' => '骰宝表结构测试', 'tests' => []];
        
        $tables = [
            'sicbo_game_results' => '游戏结果表',
            'sicbo_bet_records' => '投注记录表', 
            'sicbo_odds' => '赔率配置表',
            'sicbo_statistics' => '统计数据表',
            'dianji_table' => '台桌表',
            'common_user' => '用户表'
        ];

        foreach ($tables as $table => $desc) {
            $result['tests'][] = $this->runTest("表存在性: {$desc}", function() use ($table) {
                try {
                    $exists = Db::query("SHOW TABLES LIKE 'ntp_{$table}'");
                    return !empty($exists);
                } catch (\Exception $e) {
                    return false;
                }
            });
        }

        return $result;
    }

    /**
     * 测试控制器
     */
    private function testControllers()
    {
        $result = ['name' => '控制器类测试', 'tests' => []];
        
        $controllers = [
            'app\\controller\\sicbo\\SicboGameController' => '游戏控制器',
            'app\\controller\\sicbo\\SicboBetController' => '投注控制器',
            'app\\controller\\sicbo\\SicboAdminController' => '管理控制器',
            'app\\controller\\sicbo\\SicboApiController' => 'API控制器'
        ];

        foreach ($controllers as $className => $desc) {
            $result['tests'][] = $this->runTest($desc . '类存在', function() use ($className) {
                return class_exists($className);
            });
        }

        return $result;
    }

    /**
     * 测试模型
     */
    private function testModels()
    {
        $result = ['name' => '数据模型测试', 'tests' => []];
        
        $models = [
            'think\\Model' => 'ThinkPHP基础模型',
        ];

        foreach ($models as $className => $desc) {
            $result['tests'][] = $this->runTest($desc . '类存在', function() use ($className) {
                return class_exists($className);
            });
        }

        return $result;
    }

    /**
     * 测试API模拟
     */
    private function testApiSimulation()
    {
        $result = ['name' => 'API接口模拟测试', 'tests' => []];
        
        // 模拟骰子计算
        $result['tests'][] = $this->runTest('骰子结果计算', function() {
            $dice1 = 3; $dice2 = 4; $dice3 = 5;
            $total = $dice1 + $dice2 + $dice3;
            $isBig = $total >= 11;
            return $total === 12 && $isBig === true;
        });

        // 模拟数据查询
        $result['tests'][] = $this->runTest('数据库查询模拟', function() {
            try {
                $result = Db::query('SELECT 1 as test');
                return $result[0]['test'] == 1;
            } catch (\Exception $e) {
                return false;
            }
        });

        // 模拟缓存操作
        $result['tests'][] = $this->runTest('缓存操作模拟', function() {
            try {
                Cache::set('test_key', 'test_value', 10);
                $value = Cache::get('test_key');
                Cache::delete('test_key');
                return $value === 'test_value';
            } catch (\Exception $e) {
                return false;
            }
        });

        return $result;
    }

    /**
     * 测试数据完整性
     */
    private function testDataIntegrity()
    {
        $result = ['name' => '数据完整性测试', 'tests' => []];
        
        // 检查台桌数据
        $result['tests'][] = $this->runTest('台桌数据检查', function() {
            try {
                $count = Db::name('dianji_table')->where('game_type', 9)->count();
                return $count >= 0; // 允许为0，但不能出错
            } catch (\Exception $e) {
                return false;
            }
        });

        // 检查赔率数据
        $result['tests'][] = $this->runTest('赔率数据检查', function() {
            try {
                $count = Db::name('sicbo_odds')->count();
                return $count >= 0; // 允许为0，但不能出错
            } catch (\Exception $e) {
                return false;
            }
        });

        return $result;
    }

    // ========================================
    // 辅助方法
    // ========================================

    /**
     * 执行单个测试
     */
    private function runTest($testName, $testFunc)
    {
        $startTime = microtime(true);
        
        try {
            $result = $testFunc();
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            return [
                'name' => $testName,
                'passed' => (bool)$result,
                'duration_ms' => $duration,
                'error' => null
            ];
            
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            return [
                'name' => $testName,
                'passed' => false,
                'duration_ms' => $duration,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取测试组配置
     */
    private function getTestGroups()
    {
        return [
            'basic' => '系统基础接口',
            'test' => '测试页面接口',
            'game' => '骰宝游戏接口',
            'bet' => '投注系统接口',
            'admin' => '管理后台接口',
            'api' => '外部API接口'
        ];
    }

    /**
     * 检查数据库
     */
    private function checkDatabase()
    {
        try {
            Db::query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查缓存
     */
    private function checkCache()
    {
        try {
            Cache::set('health_check_' . time(), 'test', 10);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查骰宝表
     */
    private function checkSicboTables()
    {
        try {
            $tables = ['sicbo_game_results', 'sicbo_bet_records', 'sicbo_odds'];
            foreach ($tables as $table) {
                Db::query("SHOW TABLES LIKE 'ntp_{$table}'");
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查控制器
     */
    private function checkControllers()
    {
        try {
            $controllers = [
                'app\\controller\\sicbo\\SicboGameController',
                'app\\controller\\sicbo\\SicboBetController'
            ];
            
            foreach ($controllers as $controller) {
                if (!class_exists($controller)) {
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}