<?php
/**
 * 调试控制器 - 临时添加骰宝系统测试代码
 */
namespace app\controller;

use think\facade\Db;
use think\facade\Cache;

class Debug
{
    /**
     * 系统信息页面 - 原有代码保持不变
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
     * 测试健康检查 - 原有代码保持不变
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
     * 测试简单的JSON响应 - 临时改为骰宝系统测试
     */
    public function test()
    {
        // ======================================
        // 骰宝系统完整诊断测试
        // ======================================
        
        $startTime = microtime(true);
        $results = [
            'test_name' => '骰宝系统完整诊断',
            'start_time' => date('Y-m-d H:i:s'),
            'tests' => []
        ];

        try {
            // 1. 数据库基础检查
            $results['tests']['database_basic'] = $this->testDatabaseBasic();
            
            // 2. 骰宝表检查
            $results['tests']['sicbo_tables'] = $this->testSicboTables();
            
            // 3. 模型类检查
            $results['tests']['models'] = $this->testModels();
            
            // 4. 控制器检查
            $results['tests']['controllers'] = $this->testControllers();
            
            // 5. 数据完整性检查
            $results['tests']['data_integrity'] = $this->testDataIntegrity();
            
            // 6. 接口模拟测试
            $results['tests']['interface_simulation'] = $this->testInterfaceSimulation();

            $endTime = microtime(true);
            $results['duration_ms'] = round(($endTime - $startTime) * 1000, 2);
            $results['status'] = 'completed';

            return json($results);

        } catch (\Exception $e) {
            $endTime = microtime(true);
            $results['duration_ms'] = round(($endTime - $startTime) * 1000, 2);
            $results['status'] = 'error';
            $results['error'] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];

            return json($results);
        }
    }

    /**
     * 路由列表 - 临时改为骰宝修复工具
     */
    public function routes()
    {
        // ======================================
        // 骰宝系统快速修复工具
        // ======================================
        
        try {
            $fixes = [];
            $results = [
                'tool_name' => '骰宝系统快速修复工具',
                'timestamp' => date('Y-m-d H:i:s'),
                'fixes_applied' => []
            ];

            // 1. 检查并创建基础台桌数据
            $tableCount = Db::name('dianji_table')->where('game_type', 9)->count();
            if ($tableCount == 0) {
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
            }

            // 2. 检查并创建基础赔率数据
            $oddsCount = Db::name('sicbo_odds')->count();
            if ($oddsCount == 0) {
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
            }

            // 3. 检查并创建测试用户（如果用户表存在）
            try {
                $userCount = Db::name('user')->count();
                if ($userCount == 0) {
                    $userId = Db::name('user')->insertGetId([
                        'username' => 'testuser',
                        'password' => md5('123456'),
                        'money_balance' => 10000.00,
                        'create_time' => time(),
                        'update_time' => time()
                    ]);
                    $fixes[] = "创建了测试用户 ID: {$userId}，余额: 10000";
                }
            } catch (\Exception $e) {
                $fixes[] = "用户表检查跳过: " . $e->getMessage();
            }

            // 4. 创建示例游戏结果
            $resultCount = Db::name('sicbo_game_results')->count();
            if ($resultCount == 0) {
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
            }

            // 5. 清理缓存
            Cache::clear();
            $fixes[] = "清理了系统缓存";

            $results['fixes_applied'] = $fixes;
            $results['status'] = 'success';
            $results['message'] = '修复完成，可以开始测试接口了！';

            // 6. 验证修复结果
            $verification = [
                'tables_count' => Db::name('dianji_table')->where('game_type', 9)->count(),
                'odds_count' => Db::name('sicbo_odds')->count(),
                'results_count' => Db::name('sicbo_game_results')->count()
            ];
            $results['verification'] = $verification;

            return json($results);

        } catch (\Exception $e) {
            return json([
                'status' => 'error',
                'error' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ],
                'completed_fixes' => $fixes ?? []
            ]);
        }
    }

    // ======================================
    // 测试辅助方法
    // ======================================

    private function testDatabaseBasic()
    {
        $result = ['name' => '数据库基础检查', 'status' => 'ok', 'details' => []];
        
        try {
            // 连接测试
            $version = Db::query('SELECT VERSION() as version');
            $result['details']['mysql_version'] = $version[0]['version'];
            
            // 表前缀检查
            $prefix = config('database.connections.mysql.prefix', '');
            $result['details']['table_prefix'] = $prefix;
            
            // 字符集检查
            $charset = Db::query('SELECT @@character_set_database as charset');
            $result['details']['charset'] = $charset[0]['charset'];
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    private function testSicboTables()
    {
        $result = ['name' => '骰宝表检查', 'status' => 'ok', 'tables' => []];
        
        $requiredTables = [
            'ntp_sicbo_game_results' => '游戏结果表',
            'ntp_sicbo_bet_records' => '投注记录表',
            'ntp_sicbo_odds' => '赔率配置表',
            'ntp_sicbo_statistics' => '统计表',
            'ntp_dianji_table' => '台桌表'
        ];
        
        foreach ($requiredTables as $table => $desc) {
            try {
                $exists = Db::query("SHOW TABLES LIKE '{$table}'");
                if (!empty($exists)) {
                    $count = Db::query("SELECT COUNT(*) as count FROM {$table}");
                    $result['tables'][$table] = [
                        'desc' => $desc,
                        'exists' => true,
                        'count' => $count[0]['count']
                    ];
                } else {
                    $result['tables'][$table] = [
                        'desc' => $desc,
                        'exists' => false,
                        'count' => 0
                    ];
                    $result['status'] = 'warning';
                }
            } catch (\Exception $e) {
                $result['tables'][$table] = [
                    'desc' => $desc,
                    'exists' => false,
                    'error' => $e->getMessage()
                ];
                $result['status'] = 'error';
            }
        }
        
        return $result;
    }

    private function testModels()
    {
        $result = ['name' => '模型类检查', 'status' => 'ok', 'models' => []];
        
        $models = [
            'app\\model\\sicbo\\SicboGameResults',
            'app\\model\\sicbo\\SicboBetRecords',
            'app\\model\\sicbo\\SicboOdds',
            'app\\model\\sicbo\\SicboStatistics',
            'app\\model\\Table',
            'app\\model\\UserModel'
        ];
        
        foreach ($models as $modelClass) {
            try {
                $exists = class_exists($modelClass);
                $result['models'][$modelClass] = [
                    'exists' => $exists,
                    'instantiable' => false
                ];
                
                if ($exists) {
                    $instance = new $modelClass();
                    $result['models'][$modelClass]['instantiable'] = true;
                    $result['models'][$modelClass]['table'] = method_exists($instance, 'getTable') ? $instance->getTable() : 'unknown';
                }
                
            } catch (\Exception $e) {
                $result['models'][$modelClass] = [
                    'exists' => class_exists($modelClass),
                    'error' => $e->getMessage()
                ];
                $result['status'] = 'warning';
            }
        }
        
        return $result;
    }

    private function testControllers()
    {
        $result = ['name' => '控制器检查', 'status' => 'ok', 'controllers' => []];
        
        $controllers = [
            'app\\controller\\sicbo\\SicboGameController',
            'app\\controller\\sicbo\\SicboBetController',
            'app\\controller\\sicbo\\SicboAdminController',
            'app\\controller\\sicbo\\SicboApiController'
        ];
        
        foreach ($controllers as $controllerClass) {
            try {
                $exists = class_exists($controllerClass);
                $result['controllers'][$controllerClass] = [
                    'exists' => $exists
                ];
                
                if ($exists) {
                    $reflection = new \ReflectionClass($controllerClass);
                    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                    $result['controllers'][$controllerClass]['public_methods'] = count($methods);
                }
                
            } catch (\Exception $e) {
                $result['controllers'][$controllerClass] = [
                    'exists' => false,
                    'error' => $e->getMessage()
                ];
                $result['status'] = 'warning';
            }
        }
        
        return $result;
    }

    private function testDataIntegrity()
    {
        $result = ['name' => '数据完整性检查', 'status' => 'ok', 'checks' => []];
        
        try {
            // 检查台桌数据
            $tableCount = Db::name('dianji_table')->where('game_type', 9)->count();
            $result['checks']['sicbo_tables'] = $tableCount;
            
            // 检查赔率数据
            $oddsCount = Db::name('sicbo_odds')->count();
            $result['checks']['odds_config'] = $oddsCount;
            
            // 检查游戏结果
            $resultCount = Db::name('sicbo_game_results')->count();
            $result['checks']['game_results'] = $resultCount;
            
            // 检查投注记录
            $betCount = Db::name('sicbo_bet_records')->count();
            $result['checks']['bet_records'] = $betCount;
            
            if ($tableCount == 0 || $oddsCount == 0) {
                $result['status'] = 'warning';
                $result['message'] = '缺少基础数据，建议运行修复工具';
            }
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    private function testInterfaceSimulation()
    {
        $result = ['name' => '接口模拟测试', 'status' => 'ok', 'simulations' => []];
        
        try {
            // 模拟获取台桌信息
            $tableData = Db::name('dianji_table')->where('game_type', 9)->find();
            $result['simulations']['table_info'] = $tableData ? 'ok' : 'no_data';
            
            // 模拟获取赔率信息
            $oddsData = Db::name('sicbo_odds')->limit(5)->select();
            $result['simulations']['odds_info'] = count($oddsData) > 0 ? 'ok' : 'no_data';
            
            // 模拟获取游戏历史
            $historyData = Db::name('sicbo_game_results')->order('id desc')->limit(10)->select();
            $result['simulations']['game_history'] = count($historyData) > 0 ? 'ok' : 'no_data';
            
            // 模拟计算游戏结果
            $dice1 = 3; $dice2 = 4; $dice3 = 5;
            $total = $dice1 + $dice2 + $dice3;
            $isBig = $total >= 11;
            $isOdd = $total % 2 == 1;
            $result['simulations']['calculation'] = [
                'dice' => [$dice1, $dice2, $dice3],
                'total' => $total,
                'is_big' => $isBig,
                'is_odd' => $isOdd,
                'status' => 'ok'
            ];
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
}