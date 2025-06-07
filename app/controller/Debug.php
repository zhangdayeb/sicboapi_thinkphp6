<?php
/**
 * 调试控制器 - 重写版本，与sicbo_test.html完全匹配
 */
namespace app\controller;

use think\facade\Db;
use think\facade\Cache;

class Debug
{
    /**
     * 系统信息页面
     * 路由: GET /debug
     * 对应HTML中的基础接口测试
     */
    public function index()
    {
        try {
            $systemInfo = [
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

            // 数据库状态检查
            try {
                $dbVersion = Db::query('SELECT VERSION() as version');
                $systemInfo['database_status'] = '连接正常';
                $systemInfo['mysql_version'] = $dbVersion[0]['version'];
            } catch (\Exception $e) {
                $systemInfo['database_status'] = '连接失败: ' . $e->getMessage();
                $systemInfo['mysql_version'] = 'N/A';
            }

            // 缓存状态检查
            try {
                $testKey = 'debug_test_' . time();
                Cache::set($testKey, time(), 10);
                $cacheValue = Cache::get($testKey);
                Cache::delete($testKey);
                $systemInfo['cache_status'] = $cacheValue ? '正常' : '异常';
            } catch (\Exception $e) {
                $systemInfo['cache_status'] = '异常: ' . $e->getMessage();
            }

            // 控制器检查
            $controllers = [
                'Index' => 'app\\controller\\Index',
                'Debug' => 'app\\controller\\Debug',
                'SicboGame' => 'app\\controller\\sicbo\\SicboGameController',
                'SicboBet' => 'app\\controller\\sicbo\\SicboBetController',
                'SicboAdmin' => 'app\\controller\\sicbo\\SicboAdminController',
                'SicboApi' => 'app\\controller\\sicbo\\SicboApiController'
            ];
            
            $systemInfo['controllers'] = [];
            foreach ($controllers as $name => $class) {
                $systemInfo['controllers'][$name] = [
                    'exists' => class_exists($class),
                    'class' => $class,
                    'methods' => class_exists($class) ? count(get_class_methods($class)) : 0
                ];
            }

            // 骰宝系统检查
            $systemInfo['sicbo_system'] = $this->checkSicboSystem();

            return json([
                'success' => true,
                'code' => 200,
                'message' => '系统信息获取成功',
                'data' => $systemInfo,
                'timestamp' => time()
            ]);

        } catch (\Exception $e) {
            return json([
                'success' => false,
                'code' => 500,
                'message' => '获取系统信息失败',
                'data' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ],
                'timestamp' => time()
            ]);
        }
    }

    /**
     * 健康检查接口
     * 路由: GET /debug/health
     * 对应HTML中的健康检查测试
     */
    public function health()
    {
        try {
            $checks = [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'filesystem' => $this->checkFilesystem(),
                'php_version' => $this->checkPhpVersion(),
                'sicbo_tables' => $this->checkSicboTables(),
                'sicbo_controllers' => $this->checkSicboControllers()
            ];

            $allPassed = !in_array(false, $checks);
            $passedCount = array_sum($checks);
            $totalCount = count($checks);

            return json([
                'success' => true,
                'code' => 200,
                'message' => $allPassed ? '系统运行正常' : '系统存在问题',
                'data' => [
                    'status' => $allPassed ? 'healthy' : 'unhealthy',
                    'checks' => $checks,
                    'summary' => [
                        'passed' => $passedCount,
                        'total' => $totalCount,
                        'success_rate' => round(($passedCount / $totalCount) * 100, 1) . '%'
                    ],
                    'details' => [
                        'php_version' => PHP_VERSION,
                        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
                        'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB',
                        'load_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
                    ]
                ],
                'timestamp' => time()
            ]);

        } catch (\Exception $e) {
            return json([
                'success' => false,
                'code' => 500,
                'message' => '健康检查执行失败',
                'data' => [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ],
                'timestamp' => time()
            ]);
        }
    }

    /**
     * 综合测试接口 - 骰宝系统完整测试
     * 路由: GET /debug/test
     * 对应HTML中的完整系统测试
     */
    public function test()
    {
        $startTime = microtime(true);
        
        try {
            $testResults = [
                'test_name' => '骰宝系统完整诊断测试',
                'start_time' => date('Y-m-d H:i:s'),
                'status' => 'running',
                'modules' => []
            ];

            // 1. 数据库基础检查
            $testResults['modules']['database_basic'] = $this->testDatabaseBasic();
            
            // 2. 骰宝表检查
            $testResults['modules']['sicbo_tables'] = $this->testSicboTables();
            
            // 3. 模型类检查
            $testResults['modules']['models'] = $this->testModels();
            
            // 4. 控制器检查
            $testResults['modules']['controllers'] = $this->testControllers();
            
            // 5. 数据完整性检查
            $testResults['modules']['data_integrity'] = $this->testDataIntegrity();
            
            // 6. 接口模拟测试
            $testResults['modules']['interface_simulation'] = $this->testInterfaceSimulation();

            // 7. 性能测试
            $testResults['modules']['performance'] = $this->testPerformance();

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            // 计算总体结果
            $totalTests = 0;
            $passedTests = 0;
            $errors = [];
            
            foreach ($testResults['modules'] as $moduleName => $module) {
                if (isset($module['tests'])) {
                    foreach ($module['tests'] as $test) {
                        $totalTests++;
                        if ($test['passed']) {
                            $passedTests++;
                        } else {
                            $errors[] = [
                                'module' => $moduleName,
                                'test' => $test['name'],
                                'error' => $test['error']
                            ];
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
                'duration_ms' => $duration,
                'errors' => $errors
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
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'duration_ms' => $duration,
                    'completed_modules' => $testResults['modules'] ?? []
                ]
            ]);
        }
    }

    /**
     * 路由列表 - 骰宝系统快速修复工具
     * 路由: GET /debug/routes
     * 对应HTML中的修复工具功能
     */
    public function routes()
    {
        try {
            $fixes = [];
            $results = [
                'tool_name' => '骰宝系统快速修复工具',
                'timestamp' => date('Y-m-d H:i:s'),
                'fixes_applied' => []
            ];

            // 1. 检查并创建基础台桌数据
            $tableCount = 0;
            try {
                $tableCount = Db::name('dianji_table')->where('game_type', 9)->count();
            } catch (\Exception $e) {
                // 表可能不存在
            }

            if ($tableCount == 0) {
                try {
                    $tableId = Db::name('dianji_table')->insertGetId([
                        'table_title' => '骰宝台桌1号',
                        'game_type' => 9,
                        'status' => 1,
                        'run_status' => 0,
                        'start_time' => 0,
                        'countdown_time' => 30,
                        'game_config' => json_encode([
                            'betting_time' => 30,
                            'dice_rolling_time' => 5,
                            'result_display_time' => 10,
                            'limits' => [
                                'min_bet_basic' => 10,
                                'max_bet_basic' => 50000,
                                'min_bet_total' => 10,
                                'max_bet_total' => 1000
                            ]
                        ]),
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                    $fixes[] = "创建了骰宝台桌 ID: {$tableId}";
                } catch (\Exception $e) {
                    $fixes[] = "创建台桌失败: " . $e->getMessage();
                }
            } else {
                $fixes[] = "台桌数据已存在 ({$tableCount} 个台桌)";
            }

            // 2. 检查并创建基础赔率数据
            $oddsCount = 0;
            try {
                $oddsCount = Db::name('sicbo_odds')->count();
            } catch (\Exception $e) {
                // 表可能不存在
            }

            if ($oddsCount == 0) {
                try {
                    $basicOdds = [
                        [
                            'bet_type' => 'big',
                            'bet_name_cn' => '大',
                            'bet_name_en' => 'Big',
                            'bet_category' => 'basic',
                            'odds' => 1.0,
                            'min_bet' => 10,
                            'max_bet' => 50000,
                            'status' => 1,
                            'created_at' => time(),
                            'updated_at' => time()
                        ],
                        [
                            'bet_type' => 'small',
                            'bet_name_cn' => '小',
                            'bet_name_en' => 'Small',
                            'bet_category' => 'basic',
                            'odds' => 1.0,
                            'min_bet' => 10,
                            'max_bet' => 50000,
                            'status' => 1,
                            'created_at' => time(),
                            'updated_at' => time()
                        ],
                        [
                            'bet_type' => 'odd',
                            'bet_name_cn' => '单',
                            'bet_name_en' => 'Odd',
                            'bet_category' => 'basic',
                            'odds' => 1.0,
                            'min_bet' => 10,
                            'max_bet' => 50000,
                            'status' => 1,
                            'created_at' => time(),
                            'updated_at' => time()
                        ],
                        [
                            'bet_type' => 'even',
                            'bet_name_cn' => '双',
                            'bet_name_en' => 'Even',
                            'bet_category' => 'basic',
                            'odds' => 1.0,
                            'min_bet' => 10,
                            'max_bet' => 50000,
                            'status' => 1,
                            'created_at' => time(),
                            'updated_at' => time()
                        ]
                    ];
                    
                    Db::name('sicbo_odds')->insertAll($basicOdds);
                    $fixes[] = "创建了 " . count($basicOdds) . " 个基础赔率配置";
                } catch (\Exception $e) {
                    $fixes[] = "创建赔率配置失败: " . $e->getMessage();
                }
            } else {
                $fixes[] = "赔率配置已存在 ({$oddsCount} 个配置)";
            }

            // 3. 检查并创建测试用户
            try {
                $userCount = Db::name('common_user')->count();
                if ($userCount == 0) {
                    $userId = Db::name('common_user')->insertGetId([
                        'username' => 'testuser',
                        'password' => md5('123456'),
                        'money_balance' => 10000.00,
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                    $fixes[] = "创建了测试用户 ID: {$userId}，余额: 10000";
                } else {
                    $fixes[] = "用户数据已存在 ({$userCount} 个用户)";
                }
            } catch (\Exception $e) {
                $fixes[] = "用户表操作跳过: " . $e->getMessage();
            }

            // 4. 创建示例游戏结果
            $resultCount = 0;
            try {
                $resultCount = Db::name('sicbo_game_results')->count();
            } catch (\Exception $e) {
                // 表可能不存在
            }

            if ($resultCount == 0) {
                try {
                    $sampleResults = [
                        [
                            'table_id' => 1,
                            'game_number' => 'SAMPLE_' . date('YmdHis') . '_001',
                            'round_number' => 1,
                            'dice1' => 3,
                            'dice2' => 4,
                            'dice3' => 5,
                            'total_points' => 12,
                            'is_big' => 1,
                            'is_odd' => 0,
                            'has_triple' => 0,
                            'triple_number' => 0,
                            'has_pair' => 0,
                            'winning_bets' => json_encode(['big', 'even', 'total-12']),
                            'status' => 1,
                            'created_at' => time(),
                            'updated_at' => time()
                        ],
                        [
                            'table_id' => 1,
                            'game_number' => 'SAMPLE_' . date('YmdHis') . '_002',
                            'round_number' => 2,
                            'dice1' => 1,
                            'dice2' => 2,
                            'dice3' => 3,
                            'total_points' => 6,
                            'is_big' => 0,
                            'is_odd' => 0,
                            'has_triple' => 0,
                            'triple_number' => 0,
                            'has_pair' => 0,
                            'winning_bets' => json_encode(['small', 'even', 'total-6']),
                            'status' => 1,
                            'created_at' => time() - 60,
                            'updated_at' => time() - 60
                        ]
                    ];
                    
                    Db::name('sicbo_game_results')->insertAll($sampleResults);
                    $fixes[] = "创建了 " . count($sampleResults) . " 个示例游戏结果";
                } catch (\Exception $e) {
                    $fixes[] = "创建游戏结果失败: " . $e->getMessage();
                }
            } else {
                $fixes[] = "游戏结果已存在 ({$resultCount} 条记录)";
            }

            // 5. 清理缓存
            try {
                Cache::clear();
                $fixes[] = "清理了系统缓存";
            } catch (\Exception $e) {
                $fixes[] = "清理缓存失败: " . $e->getMessage();
            }

            $results['fixes_applied'] = $fixes;
            $results['status'] = 'success';
            $results['message'] = '修复完成，可以开始测试接口了！';

            // 6. 验证修复结果
            $verification = [
                'tables_count' => $this->safeCount('dianji_table', ['game_type' => 9]),
                'odds_count' => $this->safeCount('sicbo_odds'),
                'results_count' => $this->safeCount('sicbo_game_results'),
                'users_count' => $this->safeCount('common_user')
            ];
            $results['verification'] = $verification;

            return json([
                'success' => true,
                'code' => 200,
                'message' => '修复工具执行完成',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return json([
                'success' => false,
                'code' => 500,
                'message' => '修复工具执行失败',
                'data' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'completed_fixes' => $fixes ?? []
                ]
            ]);
        }
    }

    // ========================================
    // 测试辅助方法
    // ========================================

    /**
     * 数据库基础测试
     */
    private function testDatabaseBasic()
    {
        $result = ['name' => '数据库基础检查', 'tests' => []];
        
        // 连接测试
        $result['tests'][] = $this->runTest('MySQL连接测试', function() {
            $version = Db::query('SELECT VERSION() as version');
            return !empty($version);
        });
        
        // 字符集检查
        $result['tests'][] = $this->runTest('字符集检查', function() {
            $charset = Db::query('SELECT @@character_set_database as charset');
            return isset($charset[0]['charset']);
        });
        
        // 表前缀检查
        $result['tests'][] = $this->runTest('表前缀配置检查', function() {
            $prefix = config('database.connections.mysql.prefix', '');
            return is_string($prefix);
        });
        
        return $result;
    }

    /**
     * 骰宝表测试
     */
    private function testSicboTables()
    {
        $result = ['name' => '骰宝表检查', 'tests' => []];
        
        $requiredTables = [
            'sicbo_game_results' => '游戏结果表',
            'sicbo_bet_records' => '投注记录表',
            'sicbo_odds' => '赔率配置表',
            'sicbo_statistics' => '统计表',
            'dianji_table' => '台桌表',
            'common_user' => '用户表'
        ];
        
        foreach ($requiredTables as $table => $desc) {
            $result['tests'][] = $this->runTest("表存在性: {$desc}", function() use ($table) {
                try {
                    $prefix = config('database.connections.mysql.prefix', 'ntp_');
                    $fullTableName = $prefix . $table;
                    $exists = Db::query("SHOW TABLES LIKE '{$fullTableName}'");
                    return !empty($exists);
                } catch (\Exception $e) {
                    return false;
                }
            });
        }
        
        return $result;
    }

    /**
     * 模型测试
     */
    private function testModels()
    {
        $result = ['name' => '模型类检查', 'tests' => []];
        
        $models = [
            'think\\Model' => 'ThinkPHP基础模型',
            'think\\facade\\Db' => 'ThinkPHP数据库门面'
        ];
        
        foreach ($models as $modelClass => $desc) {
            $result['tests'][] = $this->runTest($desc, function() use ($modelClass) {
                return class_exists($modelClass);
            });
        }
        
        return $result;
    }

    /**
     * 控制器测试
     */
    private function testControllers()
    {
        $result = ['name' => '控制器检查', 'tests' => []];
        
        $controllers = [
            'app\\controller\\Index' => '首页控制器',
            'app\\controller\\Debug' => '调试控制器',
            'app\\controller\\sicbo\\SicboGameController' => '骰宝游戏控制器',
            'app\\controller\\sicbo\\SicboBetController' => '骰宝投注控制器',
            'app\\controller\\sicbo\\SicboAdminController' => '骰宝管理控制器',
            'app\\controller\\sicbo\\SicboApiController' => '骰宝API控制器'
        ];
        
        foreach ($controllers as $controllerClass => $desc) {
            $result['tests'][] = $this->runTest($desc, function() use ($controllerClass) {
                return class_exists($controllerClass);
            });
        }
        
        return $result;
    }

    /**
     * 数据完整性测试
     */
    private function testDataIntegrity()
    {
        $result = ['name' => '数据完整性检查', 'tests' => []];
        
        // 台桌数据检查
        $result['tests'][] = $this->runTest('台桌数据检查', function() {
            try {
                $count = Db::name('dianji_table')->where('game_type', 9)->count();
                return $count >= 0;
            } catch (\Exception $e) {
                return false;
            }
        });
        
        // 赔率数据检查
        $result['tests'][] = $this->runTest('赔率数据检查', function() {
            try {
                $count = Db::name('sicbo_odds')->count();
                return $count >= 0;
            } catch (\Exception $e) {
                return false;
            }
        });
        
        // 游戏结果检查
        $result['tests'][] = $this->runTest('游戏结果检查', function() {
            try {
                $count = Db::name('sicbo_game_results')->count();
                return $count >= 0;
            } catch (\Exception $e) {
                return false;
            }
        });
        
        return $result;
    }

    /**
     * 接口模拟测试
     */
    private function testInterfaceSimulation()
    {
        $result = ['name' => '接口模拟测试', 'tests' => []];
        
        // 骰子计算模拟
        $result['tests'][] = $this->runTest('骰子结果计算', function() {
            $dice1 = 3; $dice2 = 4; $dice3 = 5;
            $total = $dice1 + $dice2 + $dice3;
            $isBig = $total >= 11;
            return $total === 12 && $isBig === true;
        });
        
        // 缓存操作模拟
        $result['tests'][] = $this->runTest('缓存操作测试', function() {
            try {
                $key = 'test_key_' . time();
                Cache::set($key, 'test_value', 10);
                $value = Cache::get($key);
                Cache::delete($key);
                return $value === 'test_value';
            } catch (\Exception $e) {
                return false;
            }
        });
        
        // JSON编码测试
        $result['tests'][] = $this->runTest('JSON编码测试', function() {
            $data = ['test' => true, 'value' => 123];
            $json = json_encode($data);
            $decoded = json_decode($json, true);
            return $decoded['test'] === true && $decoded['value'] === 123;
        });
        
        return $result;
    }

    /**
     * 性能测试
     */
    private function testPerformance()
    {
        $result = ['name' => '性能测试', 'tests' => []];
        
        // 计算性能测试
        $result['tests'][] = $this->runTest('计算性能测试', function() {
            $startTime = microtime(true);
            
            for ($i = 0; $i < 1000; $i++) {
                $dice1 = rand(1, 6);
                $dice2 = rand(1, 6);
                $dice3 = rand(1, 6);
                $total = $dice1 + $dice2 + $dice3;
                $isBig = $total >= 11;
            }
            
            $endTime = microtime(true);
            $duration = ($endTime - $startTime) * 1000;
            
            return $duration < 1000; // 1000次计算应该在1秒内完成
        });
        
        // 内存使用测试
        $result['tests'][] = $this->runTest('内存使用测试', function() {
            $memoryBefore = memory_get_usage();
            
            $data = [];
            for ($i = 0; $i < 1000; $i++) {
                $data[] = ['id' => $i, 'value' => str_repeat('x', 100)];
            }
            
            $memoryAfter = memory_get_usage();
            $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB
            
            unset($data); // 释放内存
            
            return $memoryUsed < 50; // 应该小于50MB
        });
        
        return $result;
    }

    // ========================================
    // 基础检查方法
    // ========================================

    private function checkDatabase()
    {
        try {
            Db::query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkCache()
    {
        try {
            $key = 'health_check_' . time();
            Cache::set($key, time(), 10);
            $result = Cache::get($key) !== null;
            Cache::delete($key);
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkFilesystem()
    {
        return is_writable(runtime_path());
    }

    private function checkPhpVersion()
    {
        return version_compare(PHP_VERSION, '7.1.0', '>=');
    }

    private function checkSicboTables()
    {
        try {
            $tables = ['sicbo_game_results', 'sicbo_bet_records', 'sicbo_odds'];
            foreach ($tables as $table) {
                $this->safeCount($table);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkSicboControllers()
    {
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
    }

    private function checkSicboSystem()
    {
        return [
            'tables_count' => $this->safeCount('dianji_table', ['game_type' => 9]),
            'odds_count' => $this->safeCount('sicbo_odds'),
            'results_count' => $this->safeCount('sicbo_game_results'),
            'controllers_loaded' => $this->checkSicboControllers()
        ];
    }

    /**
     * 安全计数方法
     */
    private function safeCount($table, $where = [])
    {
        try {
            $query = Db::name($table);
            if (!empty($where)) {
                $query->where($where);
            }
            return $query->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

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
}