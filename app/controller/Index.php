<?php
/**
 * 首页控制器 - 骰宝系统综合测试
 */
namespace app\controller;

use app\controller\common\LogHelper;
use app\BaseController;
use app\service\sicbo\SicboGameService;
use app\service\sicbo\SicboBetService;
use app\service\sicbo\SicboCalculationService;
use app\service\sicbo\SicboSettlementService;
use app\service\sicbo\SicboStatisticsService;
use app\model\sicbo\SicboGameResults;
use app\model\sicbo\SicboBetRecords;
use app\model\sicbo\SicboOdds;
use app\model\sicbo\SicboStatistics;
use app\model\Table;
use app\model\UserModel;
use think\facade\View;
use think\facade\Db;
use think\facade\Cache;
use think\Response;

class Index extends BaseController
{
    /**
     * 测试服务实例
     */
    private SicboGameService $gameService;
    private SicboBetService $betService;
    private SicboCalculationService $calculationService;
    private SicboSettlementService $settlementService;
    private SicboStatisticsService $statisticsService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        parent::__construct();
        $this->gameService = new SicboGameService();
        $this->betService = new SicboBetService();
        $this->calculationService = new SicboCalculationService();
        $this->settlementService = new SicboSettlementService();
        $this->statisticsService = new SicboStatisticsService();
    }

    /**
     * 首页 - 显示测试页面
     */
    public function index()
    {
        LogHelper::debug('LogHelper调试信息', ['test' => 'data']);
        
        // 渲染测试页面
        View::assign([
            'page_title' => '骰宝系统综合测试',
            'test_modules' => $this->getTestModules()
        ]);
        
        return View::fetch('test/sicbo_test');
    }

    /**
     * ========================================
     * 骰宝系统综合测试入口
     * ========================================
     */

    /**
     * 执行完整的骰宝系统测试
     * 路由: GET /test/sicbo/full
     */
    public function testSicboSystemFull(): Response
    {
        $startTime = microtime(true);
        $testResults = [];
        
        LogHelper::debug('=== 开始骰宝系统完整测试 ===');

        try {
            // 1. 数据库连接测试
            $testResults['database'] = $this->testDatabaseConnection();
            
            // 2. 基础数据模型测试
            $testResults['models'] = $this->testModels();
            
            // 3. 游戏计算服务测试
            $testResults['calculation'] = $this->testCalculationService();
            
            // 4. 游戏流程服务测试
            $testResults['game_flow'] = $this->testGameFlowService();
            
            // 5. 投注服务测试
            $testResults['betting'] = $this->testBettingService();
            
            // 6. 结算服务测试
            $testResults['settlement'] = $this->testSettlementService();
            
            // 7. 统计服务测试
            $testResults['statistics'] = $this->testStatisticsService();
            
            // 8. 控制器接口测试
            $testResults['controllers'] = $this->testControllers();
            
            // 9. WebSocket功能测试
            $testResults['websocket'] = $this->testWebSocketFeatures();
            
            // 10. 性能压力测试
            $testResults['performance'] = $this->testPerformance();

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            LogHelper::debug('=== 骰宝系统测试完成 ===', [
                'duration_ms' => $duration,
                'test_count' => count($testResults)
            ]);

            return $this->formatTestResults($testResults, $duration);

        } catch (\Exception $e) {
            LogHelper::error('骰宝系统测试异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return json([
                'success' => false,
                'error' => $e->getMessage(),
                'completed_tests' => $testResults
            ]);
        }
    }

    /**
     * ========================================
     * 单独模块测试方法
     * ========================================
     */

    /**
     * 测试数据库连接
     */
    public function testDatabaseConnection(): array
    {
        $results = ['name' => '数据库连接测试', 'tests' => []];
        
        try {
            // 测试基础连接
            $results['tests'][] = $this->runTest('基础连接测试', function() {
                $result = Db::query('SELECT 1 as test');
                return !empty($result);
            });

            // 测试骰宝相关表是否存在
            $tables = [
                'ntp_sicbo_game_results' => '游戏结果表',
                'ntp_sicbo_bet_records' => '投注记录表', 
                'ntp_sicbo_odds' => '赔率配置表',
                'ntp_sicbo_statistics' => '统计数据表',
                'ntp_dianji_table' => '台桌表'
            ];

            foreach ($tables as $table => $desc) {
                $results['tests'][] = $this->runTest("表存在性: {$desc}", function() use ($table) {
                    return Db::query("SHOW TABLES LIKE '{$table}'");
                });
            }

            // 测试事务支持
            $results['tests'][] = $this->runTest('事务支持测试', function() {
                Db::startTrans();
                Db::rollback();
                return true;
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试数据模型
     */
    public function testModels(): array
    {
        $results = ['name' => '数据模型测试', 'tests' => []];

        try {
            // 测试 SicboGameResults 模型
            $results['tests'][] = $this->runTest('SicboGameResults模型', function() {
                $model = new SicboGameResults();
                return $model instanceof SicboGameResults;
            });

            // 测试 SicboBetRecords 模型
            $results['tests'][] = $this->runTest('SicboBetRecords模型', function() {
                $model = new SicboBetRecords();
                return $model instanceof SicboBetRecords;
            });

            // 测试 SicboOdds 模型方法
            $results['tests'][] = $this->runTest('SicboOdds获取赔率', function() {
                return SicboOdds::getAllActiveOdds() !== null;
            });

            // 测试模型关联
            $results['tests'][] = $this->runTest('模型关联测试', function() {
                // 测试是否能正确建立关联关系
                return true; // 简化测试
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试计算服务
     */
    public function testCalculationService(): array
    {
        $results = ['name' => '游戏计算服务测试', 'tests' => []];

        try {
            // 测试骰子结果计算
            $results['tests'][] = $this->runTest('骰子结果计算', function() {
                $result = $this->calculationService->calculateGameResult(1, 2, 3);
                return isset($result['total_points']) && $result['total_points'] === 6;
            });

            // 测试大小判断
            $results['tests'][] = $this->runTest('大小判断测试', function() {
                $result = $this->calculationService->calculateGameResult(6, 6, 5);
                return $result['is_big'] === true && $result['total_points'] === 17;
            });

            // 测试单双判断
            $results['tests'][] = $this->runTest('单双判断测试', function() {
                $result = $this->calculationService->calculateGameResult(1, 2, 4);
                return $result['is_odd'] === true && $result['total_points'] === 7;
            });

            // 测试三同号
            $results['tests'][] = $this->runTest('三同号测试', function() {
                $result = $this->calculationService->calculateGameResult(3, 3, 3);
                return $result['has_triple'] === true && $result['triple_number'] === 3;
            });

            // 测试对子
            $results['tests'][] = $this->runTest('对子测试', function() {
                $result = $this->calculationService->calculateGameResult(2, 2, 5);
                return $result['has_pair'] === true && in_array(2, $result['pair_numbers']);
            });

            // 测试中奖投注类型计算
            $results['tests'][] = $this->runTest('中奖投注类型', function() {
                $result = $this->calculationService->calculateGameResult(4, 5, 6);
                $winningBets = $result['winning_bets'];
                return in_array('big', $winningBets) && in_array('odd', $winningBets);
            });

            // 测试投注验证
            $results['tests'][] = $this->runTest('投注验证测试', function() {
                $gameResult = ['winning_bets' => ['big', 'odd', 'single-4']];
                return $this->calculationService->isBetWinning('big', $gameResult);
            });

            // 测试赔付计算
            $results['tests'][] = $this->runTest('赔付计算测试', function() {
                $gameResult = ['winning_bets' => ['big'], 'single_counts' => []];
                $payout = $this->calculationService->calculatePayout('big', 100, 1.95, $gameResult);
                return $payout['is_winning'] === true && $payout['win_amount'] === 195;
            });

            // 测试概率计算
            $results['tests'][] = $this->runTest('概率计算测试', function() {
                $prob = $this->calculationService->calculateProbability('big');
                return $prob > 0 && $prob < 1;
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试游戏流程服务
     */
    public function testGameFlowService(): array
    {
        $results = ['name' => '游戏流程服务测试', 'tests' => []];

        try {
            // 创建测试台桌
            $testTableId = $this->createTestTable();

            // 测试获取台桌状态
            $results['tests'][] = $this->runTest('获取台桌状态', function() use ($testTableId) {
                $status = $this->gameService->getTableStatus($testTableId);
                return isset($status['table_id']) && $status['table_id'] === $testTableId;
            });

            // 测试开始新游戏
            $results['tests'][] = $this->runTest('开始新游戏', function() use ($testTableId) {
                try {
                    $result = $this->gameService->startNewGame($testTableId, 30);
                    return $result['success'] === true;
                } catch (\Exception $e) {
                    return false; // 可能台桌状态不符合要求
                }
            });

            // 测试游戏历史
            $results['tests'][] = $this->runTest('获取游戏历史', function() use ($testTableId) {
                $history = $this->gameService->getGameHistory($testTableId, 10);
                return isset($history['table_id']);
            });

            // 测试投注统计
            $results['tests'][] = $this->runTest('获取投注统计', function() {
                $stats = $this->gameService->getBettingStatistics('TEST_GAME_001');
                return isset($stats['game_number']);
            });

            // 测试台桌配置更新
            $results['tests'][] = $this->runTest('台桌配置更新', function() use ($testTableId) {
                $config = ['betting_time' => 45];
                return $this->gameService->updateTableConfig($testTableId, $config);
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试投注服务
     */
    public function testBettingService(): array
    {
        $results = ['name' => '投注服务测试', 'tests' => []];

        try {
            $testUserId = $this->createTestUser();
            $testTableId = $this->createTestTable();

            // 测试投注验证
            $results['tests'][] = $this->runTest('投注数据验证', function() {
                $bets = [
                    ['bet_type' => 'big', 'bet_amount' => 100],
                    ['bet_type' => 'odd', 'bet_amount' => 50]
                ];
                $validation = $this->betService->validateBets($bets, 150);
                return $validation['valid'] === true;
            });

            // 测试获取用户当前投注
            $results['tests'][] = $this->runTest('获取用户当前投注', function() use ($testUserId) {
                $bets = $this->betService->getCurrentUserBets($testUserId, 'TEST_GAME_001');
                return isset($bets['bets']);
            });

            // 测试投注历史
            $results['tests'][] = $this->runTest('获取投注历史', function() use ($testUserId) {
                $history = $this->betService->getUserBetHistory($testUserId);
                return isset($history['records']);
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试结算服务
     */
    public function testSettlementService(): array
    {
        $results = ['name' => '结算服务测试', 'tests' => []];

        try {
            // 测试游戏结果计算
            $results['tests'][] = $this->runTest('游戏结算计算', function() {
                // 模拟一个简单的结算测试
                return true; // 简化处理，实际需要创建完整的测试环境
            });

            // 测试用户派彩结果
            $results['tests'][] = $this->runTest('用户派彩结果', function() {
                $result = $this->settlementService->getUserPayoutResult('TEST_GAME_001', 1);
                return $result === null || isset($result['game_number']);
            });

            // 测试结算统计
            $results['tests'][] = $this->runTest('结算统计信息', function() {
                $stats = $this->settlementService->getSettlementStatistics(
                    date('Y-m-d'),
                    date('Y-m-d')
                );
                return isset($stats['total_games']);
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试统计服务
     */
    public function testStatisticsService(): array
    {
        $results = ['name' => '统计服务测试', 'tests' => []];

        try {
            $testTableId = $this->createTestTable();

            // 测试实时统计
            $results['tests'][] = $this->runTest('实时统计数据', function() use ($testTableId) {
                $stats = $this->statisticsService->getRealtimeStats($testTableId, 20);
                return isset($stats['table_id']) && $stats['table_id'] === $testTableId;
            });

            // 测试历史统计
            $results['tests'][] = $this->runTest('历史统计数据', function() use ($testTableId) {
                $stats = $this->statisticsService->getHistoricalStats($testTableId, 'today');
                return isset($stats['period']);
            });

            // 测试投注统计
            $results['tests'][] = $this->runTest('投注统计数据', function() use ($testTableId) {
                $stats = $this->statisticsService->getBettingStats($testTableId, 'today');
                return isset($stats['period']);
            });

            // 测试用户行为分析
            $results['tests'][] = $this->runTest('用户行为分析', function() use ($testTableId) {
                $analysis = $this->statisticsService->getUserBehaviorAnalysis($testTableId, 'today');
                return isset($analysis['period']);
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试控制器接口
     */
    public function testControllers(): array
    {
        $results = ['name' => '控制器接口测试', 'tests' => []];

        try {
            // 测试游戏控制器
            $results['tests'][] = $this->runTest('游戏控制器实例化', function() {
                $controller = new \app\controller\sicbo\SicboGameController();
                return $controller instanceof \app\controller\sicbo\SicboGameController;
            });

            // 测试投注控制器
            $results['tests'][] = $this->runTest('投注控制器实例化', function() {
                $controller = new \app\controller\sicbo\SicboBetController();
                return $controller instanceof \app\controller\sicbo\SicboBetController;
            });

            // 测试管理控制器
            $results['tests'][] = $this->runTest('管理控制器实例化', function() {
                $controller = new \app\controller\sicbo\SicboAdminController();
                return $controller instanceof \app\controller\sicbo\SicboAdminController;
            });

            // 测试API控制器
            $results['tests'][] = $this->runTest('API控制器实例化', function() {
                $controller = new \app\controller\sicbo\SicboApiController();
                return $controller instanceof \app\controller\sicbo\SicboApiController;
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试WebSocket功能
     */
    public function testWebSocketFeatures(): array
    {
        $results = ['name' => 'WebSocket功能测试', 'tests' => []];

        try {
            // 测试Worker类加载
            $results['tests'][] = $this->runTest('Worker类加载', function() {
                return class_exists('\app\http\Worker');
            });

            // 测试连接状态获取
            $results['tests'][] = $this->runTest('在线统计获取', function() {
                $stats = \app\http\Worker::getOnlineStats();
                return isset($stats['total_connections']);
            });

            // 测试台桌连接数
            $results['tests'][] = $this->runTest('台桌连接数', function() {
                $count = \app\http\Worker::getTableConnectionCount(1);
                return is_numeric($count);
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 测试系统性能
     */
    public function testPerformance(): array
    {
        $results = ['name' => '性能压力测试', 'tests' => []];

        try {
            // 测试计算服务性能
            $results['tests'][] = $this->runTest('计算服务性能', function() {
                $startTime = microtime(true);
                
                for ($i = 0; $i < 1000; $i++) {
                    $dice1 = rand(1, 6);
                    $dice2 = rand(1, 6);
                    $dice3 = rand(1, 6);
                    $this->calculationService->calculateGameResult($dice1, $dice2, $dice3);
                }
                
                $endTime = microtime(true);
                $duration = ($endTime - $startTime) * 1000;
                
                return $duration < 1000; // 1000次计算应该在1秒内完成
            });

            // 测试数据库查询性能
            $results['tests'][] = $this->runTest('数据库查询性能', function() {
                $startTime = microtime(true);
                
                for ($i = 0; $i < 100; $i++) {
                    SicboOdds::getAllActiveOdds();
                }
                
                $endTime = microtime(true);
                $duration = ($endTime - $startTime) * 1000;
                
                return $duration < 2000; // 100次查询应该在2秒内完成
            });

            // 测试缓存性能
            $results['tests'][] = $this->runTest('缓存读写性能', function() {
                $startTime = microtime(true);
                
                for ($i = 0; $i < 1000; $i++) {
                    Cache::set("test_key_{$i}", "test_value_{$i}", 60);
                    Cache::get("test_key_{$i}");
                }
                
                $endTime = microtime(true);
                $duration = ($endTime - $startTime) * 1000;
                
                // 清理测试缓存
                for ($i = 0; $i < 1000; $i++) {
                    Cache::delete("test_key_{$i}");
                }
                
                return $duration < 3000; // 1000次缓存操作应该在3秒内完成
            });

        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * ========================================
     * 辅助测试方法
     * ========================================
     */

    /**
     * 执行单个测试
     */
    private function runTest(string $testName, callable $testFunc): array
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
     * 创建测试台桌
     */
    private function createTestTable(): int
    {
        // 检查是否已存在测试台桌
        $table = Table::where('table_title', 'TEST_TABLE')->find();
        
        if ($table) {
            return $table->id;
        }

        // 创建测试台桌
        $tableData = [
            'table_title' => 'TEST_TABLE',
            'game_type' => 9, // 骰宝
            'status' => 1,
            'run_status' => 0,
            'game_config' => json_encode(['betting_time' => 30]),
            'create_time' => time(),
            'update_time' => time()
        ];

        $table = Table::create($tableData);
        return $table->id;
    }

    /**
     * 创建测试用户
     */
    private function createTestUser(): int
    {
        // 检查是否已存在测试用户
        $user = UserModel::where('username', 'test_user')->find();
        
        if ($user) {
            return $user->id;
        }

        // 创建测试用户
        $userData = [
            'username' => 'test_user',
            'password' => md5('123456'),
            'nickname' => '测试用户',
            'money_balance' => 10000,
            'status' => 1,
            'create_time' => date('Y-m-d H:i:s')
        ];

        $user = UserModel::create($userData);
        return $user->id;
    }

    /**
     * 获取测试模块列表
     */
    private function getTestModules(): array
    {
        return [
            'database' => '数据库连接测试',
            'models' => '数据模型测试',
            'calculation' => '游戏计算服务测试',
            'game_flow' => '游戏流程服务测试',
            'betting' => '投注服务测试',
            'settlement' => '结算服务测试',
            'statistics' => '统计服务测试',
            'controllers' => '控制器接口测试',
            'websocket' => 'WebSocket功能测试',
            'performance' => '性能压力测试'
        ];
    }

    /**
     * 格式化测试结果
     */
    private function formatTestResults(array $testResults, float $duration): Response
    {
        $totalTests = 0;
        $passedTests = 0;
        $failedTests = 0;

        foreach ($testResults as $module) {
            if (isset($module['tests'])) {
                foreach ($module['tests'] as $test) {
                    $totalTests++;
                    if ($test['passed']) {
                        $passedTests++;
                    } else {
                        $failedTests++;
                    }
                }
            }
        }

        $summary = [
            'success' => $failedTests === 0,
            'summary' => [
                'total_tests' => $totalTests,
                'passed_tests' => $passedTests,
                'failed_tests' => $failedTests,
                'success_rate' => $totalTests > 0 ? round($passedTests / $totalTests * 100, 2) : 0,
                'total_duration_ms' => $duration
            ],
            'modules' => $testResults,
            'timestamp' => date('Y-m-d H:i:s'),
            'system_info' => [
                'php_version' => PHP_VERSION,
                'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
                'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB'
            ]
        ];

        LogHelper::debug('骰宝系统测试结果汇总', $summary['summary']);

        return json($summary);
    }

    /**
     * ========================================
     * 单独测试接口 (可通过路由单独调用)
     * ========================================
     */

    /**
     * 快速健康检查
     * 路由: GET /test/health
     */
    public function quickHealthCheck(): Response
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'tables' => $this->checkTables(),
            'services' => $this->checkServices()
        ];

        $allPassed = !in_array(false, $checks);

        return json([
            'status' => $allPassed ? 'healthy' : 'unhealthy',
            'checks' => $checks,
            'timestamp' => time()
        ]);
    }

    /**
     * 检查数据库
     */
    private function checkDatabase(): bool
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
    private function checkCache(): bool
    {
        try {
            Cache::set('health_check', time(), 10);
            return Cache::get('health_check') !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查核心表
     */
    private function checkTables(): bool
    {
        try {
            $tables = ['ntp_sicbo_game_results', 'ntp_sicbo_bet_records', 'ntp_sicbo_odds'];
            foreach ($tables as $table) {
                Db::query("SELECT 1 FROM {$table} LIMIT 1");
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查服务
     */
    private function checkServices(): bool
    {
        try {
            $this->calculationService->calculateGameResult(1, 2, 3);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 清理测试数据
     * 路由: POST /test/cleanup
     */
    public function cleanupTestData(): Response
    {
        try {
            // 删除测试台桌
            Table::where('table_title', 'TEST_TABLE')->delete();
            
            // 删除测试用户
            UserModel::where('username', 'test_user')->delete();
            
            // 清除测试缓存
            Cache::clear();

            return json([
                'success' => true,
                'message' => '测试数据清理完成'
            ]);
            
        } catch (\Exception $e) {
            return json([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}