<?php


namespace app\service\sicbo;

use app\controller\common\LogHelper;

/**
 * ========================================
 * 骰宝游戏计算服务类
 * ========================================
 * 
 * 功能概述：
 * - 处理骰宝游戏的核心计算逻辑
 * - 计算三个骰子的各种组合结果
 * - 判断所有投注类型的中奖情况
 * - 提供概率计算和统计分析
 * - 验证游戏结果的合法性
 * 
 * 骰宝规则说明：
 * - 使用3个六面骰子（1-6点）
 * - 总点数范围：3-18点
 * - 大小：4-10为小，11-17为大，3和18为通杀
 * - 单双：总点数的奇偶性
 * - 特殊组合：三同号、对子、组合等
 * 
 * @package app\service\sicbo
 * @author  系统开发团队
 * @version 1.0
 */
class SicboCalculationService
{
    /**
     * 投注类型常量定义
     */
    const BET_TYPE_SMALL = 'small';          // 小(4-10)
    const BET_TYPE_BIG = 'big';              // 大(11-17)
    const BET_TYPE_ODD = 'odd';              // 单
    const BET_TYPE_EVEN = 'even';            // 双
    const BET_TYPE_ANY_TRIPLE = 'any-triple'; // 任意三同号
    
    /**
     * 特殊点数（通杀大小）
     */
    const KILL_ALL_TOTALS = [3, 18];
    
    /**
     * 大小点数范围
     */
    const SMALL_RANGE = [4, 5, 6, 7, 8, 9, 10];
    const BIG_RANGE = [11, 12, 13, 14, 15, 16, 17];

    /**
     * ========================================
     * 主要计算方法
     * ========================================
     */

    /**
     * 执行完整的骰宝游戏计算
     * 主入口方法，计算所有游戏结果和中奖投注类型
     * 
     * @param int $dice1 第一个骰子点数(1-6)
     * @param int $dice2 第二个骰子点数(1-6)
     * @param int $dice3 第三个骰子点数(1-6)
     * @return array 完整的游戏计算结果
     */
    public function calculateGameResult(int $dice1, int $dice2, int $dice3): array
    {
        LogHelper::debug('=== 骰宝游戏计算开始 ===', [
            'dice1' => $dice1,
            'dice2' => $dice2,
            'dice3' => $dice3
        ]);

        // 验证骰子点数的合法性
        $this->validateDiceValues($dice1, $dice2, $dice3);

        // 基础数据计算
        $basicResults = $this->calculateBasicResults($dice1, $dice2, $dice3);
        
        // 特殊组合计算
        $specialResults = $this->calculateSpecialResults($dice1, $dice2, $dice3);
        
        // 计算所有中奖的投注类型
        $winningBets = $this->calculateWinningBets($basicResults, $specialResults);
        
        // 生成完整结果
        $completeResult = array_merge($basicResults, $specialResults, [
            'winning_bets' => $winningBets,
            'winning_count' => count($winningBets),
            'calculation_time' => microtime(true)
        ]);

        LogHelper::debug('骰宝游戏计算完成', [
            'total_points' => $completeResult['total_points'],
            'is_big' => $completeResult['is_big'],
            'is_odd' => $completeResult['is_odd'],
            'winning_bets_count' => count($winningBets),
            'winning_bets' => $winningBets
        ]);

        return $completeResult;
    }

    /**
     * ========================================
     * 基础结果计算
     * ========================================
     */

    /**
     * 计算基础游戏结果
     * 包括总点数、大小、单双等基本属性
     * 
     * @param int $dice1 骰子1点数
     * @param int $dice2 骰子2点数  
     * @param int $dice3 骰子3点数
     * @return array 基础计算结果
     */
    private function calculateBasicResults(int $dice1, int $dice2, int $dice3): array
    {
        $totalPoints = $dice1 + $dice2 + $dice3;
        
        // 大小判断（3和18为通杀，既不是大也不是小）
        $isBig = !in_array($totalPoints, self::KILL_ALL_TOTALS) && in_array($totalPoints, self::BIG_RANGE);
        $isSmall = !in_array($totalPoints, self::KILL_ALL_TOTALS) && in_array($totalPoints, self::SMALL_RANGE);
        
        // 单双判断
        $isOdd = ($totalPoints % 2) === 1;
        $isEven = ($totalPoints % 2) === 0;

        LogHelper::debug('基础结果计算', [
            'total_points' => $totalPoints,
            'is_big' => $isBig,
            'is_small' => $isSmall,
            'is_odd' => $isOdd,
            'is_even' => $isEven
        ]);

        return [
            'dice1' => $dice1,
            'dice2' => $dice2,
            'dice3' => $dice3,
            'total_points' => $totalPoints,
            'is_big' => $isBig,
            'is_small' => $isSmall,
            'is_odd' => $isOdd,
            'is_even' => $isEven,
            'is_kill_all' => in_array($totalPoints, self::KILL_ALL_TOTALS)
        ];
    }

    /**
     * ========================================
     * 特殊组合计算
     * ========================================
     */

    /**
     * 计算特殊组合结果
     * 包括三同号、对子、组合投注等
     * 
     * @param int $dice1 骰子1点数
     * @param int $dice2 骰子2点数
     * @param int $dice3 骰子3点数
     * @return array 特殊组合结果
     */
    private function calculateSpecialResults(int $dice1, int $dice2, int $dice3): array
    {
        $dices = [$dice1, $dice2, $dice3];
        $diceCounts = array_count_values($dices);
        $uniqueDices = array_keys($diceCounts);
        
        // 三同号检查
        $tripleResults = $this->calculateTripleResults($diceCounts);
        
        // 对子检查
        $pairResults = $this->calculatePairResults($diceCounts);
        
        // 单骰检查
        $singleResults = $this->calculateSingleResults($diceCounts);
        
        // 组合投注检查
        $comboResults = $this->calculateComboResults($uniqueDices);

        LogHelper::debug('特殊组合计算', [
            'dice_counts' => $diceCounts,
            'triple_number' => $tripleResults['triple_number'],
            'pair_numbers' => $pairResults['pair_numbers'],
            'single_numbers' => $singleResults['single_numbers'],
            'combo_pairs' => $comboResults['combo_pairs']
        ]);

        return array_merge($tripleResults, $pairResults, $singleResults, $comboResults);
    }

    /**
     * 计算三同号结果
     * 
     * @param array $diceCounts 骰子点数统计
     * @return array 三同号结果
     */
    private function calculateTripleResults(array $diceCounts): array
    {
        $hasTriple = false;
        $tripleNumber = null;
        $hasAnyTriple = false;

        foreach ($diceCounts as $dice => $count) {
            if ($count === 3) {
                $hasTriple = true;
                $tripleNumber = $dice;
                $hasAnyTriple = true;
                break;
            }
        }

        return [
            'has_triple' => $hasTriple,
            'triple_number' => $tripleNumber,
            'has_any_triple' => $hasAnyTriple
        ];
    }

    /**
     * 计算对子结果
     * 
     * @param array $diceCounts 骰子点数统计
     * @return array 对子结果
     */
    private function calculatePairResults(array $diceCounts): array
    {
        $hasPair = false;
        $pairNumbers = [];

        foreach ($diceCounts as $dice => $count) {
            if ($count === 2) {
                $hasPair = true;
                $pairNumbers[] = $dice;
            }
        }

        return [
            'has_pair' => $hasPair,
            'pair_numbers' => $pairNumbers,
            'pair_count' => count($pairNumbers)
        ];
    }

    /**
     * 计算单骰结果
     * 
     * @param array $diceCounts 骰子点数统计
     * @return array 单骰结果
     */
    private function calculateSingleResults(array $diceCounts): array
    {
        $singleNumbers = [];
        $singleCounts = [];

        foreach ($diceCounts as $dice => $count) {
            $singleNumbers[] = $dice;
            $singleCounts[$dice] = $count;
        }

        return [
            'single_numbers' => $singleNumbers,
            'single_counts' => $singleCounts
        ];
    }

    /**
     * 计算组合投注结果
     * 
     * @param array $uniqueDices 不重复的骰子点数
     * @return array 组合投注结果
     */
    private function calculateComboResults(array $uniqueDices): array
    {
        $comboPairs = [];

        // 只有在有至少2个不同点数时才有组合
        if (count($uniqueDices) >= 2) {
            sort($uniqueDices);
            
            // 生成所有两两组合
            for ($i = 0; $i < count($uniqueDices); $i++) {
                for ($j = $i + 1; $j < count($uniqueDices); $j++) {
                    $comboPairs[] = [$uniqueDices[$i], $uniqueDices[$j]];
                }
            }
        }

        return [
            'combo_pairs' => $comboPairs,
            'combo_count' => count($comboPairs)
        ];
    }

    /**
     * ========================================
     * 中奖投注类型计算
     * ========================================
     */

    /**
     * 计算所有中奖的投注类型
     * 
     * @param array $basicResults 基础计算结果
     * @param array $specialResults 特殊组合结果
     * @return array 中奖投注类型列表
     */
    private function calculateWinningBets(array $basicResults, array $specialResults): array
    {
        $winningBets = [];

        // 基础投注类型中奖判断
        $winningBets = array_merge($winningBets, $this->getBasicWinningBets($basicResults));

        // 总和投注中奖判断
        $winningBets = array_merge($winningBets, $this->getTotalWinningBets($basicResults));

        // 单骰投注中奖判断
        $winningBets = array_merge($winningBets, $this->getSingleWinningBets($specialResults));

        // 对子投注中奖判断
        $winningBets = array_merge($winningBets, $this->getPairWinningBets($specialResults));

        // 三同号投注中奖判断
        $winningBets = array_merge($winningBets, $this->getTripleWinningBets($specialResults));

        // 组合投注中奖判断
        $winningBets = array_merge($winningBets, $this->getComboWinningBets($specialResults));

        return array_unique($winningBets);
    }

    /**
     * 获取基础投注类型中奖列表
     * 
     * @param array $basicResults 基础计算结果
     * @return array 基础投注中奖列表
     */
    private function getBasicWinningBets(array $basicResults): array
    {
        $winningBets = [];

        // 大小投注（注意：3和18点通杀大小）
        if ($basicResults['is_big']) {
            $winningBets[] = self::BET_TYPE_BIG;
        }
        if ($basicResults['is_small']) {
            $winningBets[] = self::BET_TYPE_SMALL;
        }

        // 单双投注
        if ($basicResults['is_odd']) {
            $winningBets[] = self::BET_TYPE_ODD;
        }
        if ($basicResults['is_even']) {
            $winningBets[] = self::BET_TYPE_EVEN;
        }

        return $winningBets;
    }

    /**
     * 获取总和投注中奖列表
     * 
     * @param array $basicResults 基础计算结果
     * @return array 总和投注中奖列表
     */
    private function getTotalWinningBets(array $basicResults): array
    {
        $totalPoints = $basicResults['total_points'];
        return ["total-{$totalPoints}"];
    }

    /**
     * 获取单骰投注中奖列表
     * 
     * @param array $specialResults 特殊组合结果
     * @return array 单骰投注中奖列表
     */
    private function getSingleWinningBets(array $specialResults): array
    {
        $winningBets = [];
        $singleCounts = $specialResults['single_counts'];

        foreach ($singleCounts as $dice => $count) {
            // 根据出现次数决定赔率倍数
            for ($i = 0; $i < $count; $i++) {
                $winningBets[] = "single-{$dice}";
            }
        }

        return $winningBets;
    }

    /**
     * 获取对子投注中奖列表
     * 
     * @param array $specialResults 特殊组合结果
     * @return array 对子投注中奖列表
     */
    private function getPairWinningBets(array $specialResults): array
    {
        $winningBets = [];

        if ($specialResults['has_pair']) {
            foreach ($specialResults['pair_numbers'] as $pairNumber) {
                $winningBets[] = "pair-{$pairNumber}";
            }
        }

        return $winningBets;
    }

    /**
     * 获取三同号投注中奖列表
     * 
     * @param array $specialResults 特殊组合结果
     * @return array 三同号投注中奖列表
     */
    private function getTripleWinningBets(array $specialResults): array
    {
        $winningBets = [];

        if ($specialResults['has_triple']) {
            $tripleNumber = $specialResults['triple_number'];
            $winningBets[] = "triple-{$tripleNumber}";  // 指定三同号
            $winningBets[] = self::BET_TYPE_ANY_TRIPLE;   // 任意三同号
        }

        return $winningBets;
    }

    /**
     * 获取组合投注中奖列表
     * 
     * @param array $specialResults 特殊组合结果
     * @return array 组合投注中奖列表
     */
    private function getComboWinningBets(array $specialResults): array
    {
        $winningBets = [];

        foreach ($specialResults['combo_pairs'] as $comboPair) {
            $winningBets[] = "combo-{$comboPair[0]}-{$comboPair[1]}";
        }

        return $winningBets;
    }

    /**
     * ========================================
     * 投注验证和计算方法
     * ========================================
     */

    /**
     * 验证用户投注是否中奖
     * 
     * @param string $betType 投注类型
     * @param array $gameResult 游戏结果
     * @return bool 是否中奖
     */
    public function isBetWinning(string $betType, array $gameResult): bool
    {
        return in_array($betType, $gameResult['winning_bets'] ?? []);
    }

    /**
     * 计算投注的理论赔付金额
     * 
     * @param string $betType 投注类型
     * @param float $betAmount 投注金额
     * @param float $odds 赔率
     * @param array $gameResult 游戏结果
     * @return array 赔付计算结果
     */
    public function calculatePayout(string $betType, float $betAmount, float $odds, array $gameResult): array
    {
        $isWinning = $this->isBetWinning($betType, $gameResult);
        
        if (!$isWinning) {
            return [
                'is_winning' => false,
                'bet_amount' => $betAmount,
                'win_amount' => 0,
                'net_result' => -$betAmount,
                'return_amount' => 0
            ];
        }

        // 特殊处理：单骰投注可能有多倍赔付
        $multiplier = $this->getSingleBetMultiplier($betType, $gameResult);
        $winAmount = $betAmount * $odds * $multiplier;
        $returnAmount = $winAmount + $betAmount; // 赢得金额 + 返还本金

        return [
            'is_winning' => true,
            'bet_amount' => $betAmount,
            'win_amount' => $winAmount,
            'net_result' => $winAmount,
            'return_amount' => $returnAmount,
            'multiplier' => $multiplier
        ];
    }

    /**
     * 获取单骰投注的倍数
     * 单骰投注：出现1次赔1倍，出现2次赔2倍，出现3次赔3倍
     * 
     * @param string $betType 投注类型
     * @param array $gameResult 游戏结果
     * @return int 倍数
     */
    private function getSingleBetMultiplier(string $betType, array $gameResult): int
    {
        if (!preg_match('/^single-(\d)$/', $betType, $matches)) {
            return 1;
        }

        $diceNumber = (int)$matches[1];
        $singleCounts = $gameResult['single_counts'] ?? [];
        
        return $singleCounts[$diceNumber] ?? 0;
    }

    /**
     * ========================================
     * 概率和统计计算
     * ========================================
     */

    /**
     * 计算投注类型的理论概率
     * 
     * @param string $betType 投注类型
     * @return float 理论概率 (0-1之间)
     */
    public function calculateProbability(string $betType): float
    {
        $totalOutcomes = 6 * 6 * 6; // 总共216种可能

        switch (true) {
            // 大小投注
            case $betType === self::BET_TYPE_BIG:
            case $betType === self::BET_TYPE_SMALL:
                return 105 / $totalOutcomes; // 各105种情况
                
            // 单双投注
            case $betType === self::BET_TYPE_ODD:
            case $betType === self::BET_TYPE_EVEN:
                return 108 / $totalOutcomes; // 各108种情况
                
            // 任意三同号
            case $betType === self::BET_TYPE_ANY_TRIPLE:
                return 6 / $totalOutcomes; // 6种三同号
                
            // 指定三同号
            case preg_match('/^triple-(\d)$/', $betType):
                return 1 / $totalOutcomes; // 每种三同号1种情况
                
            // 指定对子
            case preg_match('/^pair-(\d)$/', $betType):
                return 15 / $totalOutcomes; // 每种对子15种情况
                
            // 单骰投注
            case preg_match('/^single-(\d)$/', $betType):
                return 91 / $totalOutcomes; // 每个数字91种情况（1次+2次+3次）
                
            // 总和投注
            case preg_match('/^total-(\d+)$/', $betType, $matches):
                return $this->getTotalProbability((int)$matches[1]);
                
            // 组合投注
            case preg_match('/^combo-(\d)-(\d)$/', $betType):
                return 30 / $totalOutcomes; // 每种组合30种情况
                
            default:
                return 0;
        }
    }

    /**
     * 获取总和投注的概率
     * 
     * @param int $total 总和点数
     * @return float 概率
     */
    private function getTotalProbability(int $total): float
    {
        $totalOutcomes = 216;
        
        // 各总和的可能组合数
        $combinations = [
            3 => 1, 4 => 3, 5 => 6, 6 => 10, 7 => 15, 8 => 21,
            9 => 25, 10 => 27, 11 => 27, 12 => 25, 13 => 21,
            14 => 15, 15 => 10, 16 => 6, 17 => 3, 18 => 1
        ];
        
        return ($combinations[$total] ?? 0) / $totalOutcomes;
    }

    /**
     * 计算庄家优势（House Edge）
     * 
     * @param string $betType 投注类型
     * @param float $odds 赔率
     * @return float 庄家优势百分比
     */
    public function calculateHouseEdge(string $betType, float $odds): float
    {
        $probability = $this->calculateProbability($betType);
        $expectedReturn = $probability * ($odds + 1); // 包含本金
        
        return (1 - $expectedReturn) * 100; // 转换为百分比
    }

    /**
     * ========================================
     * 游戏统计分析
     * ========================================
     */

    /**
     * 分析游戏结果趋势
     * 
     * @param array $recentResults 最近的游戏结果列表
     * @param int $analyzeCount 分析的局数
     * @return array 趋势分析结果
     */
    public function analyzeTrends(array $recentResults, int $analyzeCount = 20): array
    {
        $limitedResults = array_slice($recentResults, 0, $analyzeCount);
        
        $bigCount = 0;
        $smallCount = 0;
        $oddCount = 0;
        $evenCount = 0;
        $tripleCount = 0;
        $totalDistribution = array_fill(3, 16, 0);
        $diceDistribution = array_fill(1, 6, 0);
        
        foreach ($limitedResults as $result) {
            if ($result['is_big']) $bigCount++;
            if ($result['is_small']) $smallCount++;
            if ($result['is_odd']) $oddCount++;
            if ($result['is_even']) $evenCount++;
            if ($result['has_triple']) $tripleCount++;
            
            $totalDistribution[$result['total_points']]++;
            
            $diceDistribution[$result['dice1']]++;
            $diceDistribution[$result['dice2']]++;
            $diceDistribution[$result['dice3']]++;
        }
        
        $totalGames = count($limitedResults);
        
        return [
            'analyzed_games' => $totalGames,
            'big_rate' => $totalGames > 0 ? round($bigCount / $totalGames * 100, 2) : 0,
            'small_rate' => $totalGames > 0 ? round($smallCount / $totalGames * 100, 2) : 0,
            'odd_rate' => $totalGames > 0 ? round($oddCount / $totalGames * 100, 2) : 0,
            'even_rate' => $totalGames > 0 ? round($evenCount / $totalGames * 100, 2) : 0,
            'triple_rate' => $totalGames > 0 ? round($tripleCount / $totalGames * 100, 2) : 0,
            'total_distribution' => $totalDistribution,
            'dice_distribution' => $diceDistribution,
            'hot_numbers' => $this->getHotNumbers($diceDistribution),
            'cold_numbers' => $this->getColdNumbers($diceDistribution),
            'analysis_time' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * 获取热门号码
     * 
     * @param array $diceDistribution 骰子分布统计
     * @return array 热门号码列表
     */
    private function getHotNumbers(array $diceDistribution): array
    {
        arsort($diceDistribution);
        return array_slice(array_keys($diceDistribution), 0, 3, true);
    }

    /**
     * 获取冷门号码
     * 
     * @param array $diceDistribution 骰子分布统计
     * @return array 冷门号码列表
     */
    private function getColdNumbers(array $diceDistribution): array
    {
        asort($diceDistribution);
        return array_slice(array_keys($diceDistribution), 0, 3, true);
    }

    /**
     * ========================================
     * 工具和验证方法
     * ========================================
     */

    /**
     * 验证骰子点数的合法性
     * 
     * @param int ...$dices 骰子点数列表
     * @throws \InvalidArgumentException 当骰子点数不合法时
     */
    private function validateDiceValues(int ...$dices): void
    {
        foreach ($dices as $index => $dice) {
            if ($dice < 1 || $dice > 6) {
                throw new \InvalidArgumentException("骰子" . ($index + 1) . "的点数 {$dice} 不合法，必须在1-6之间");
            }
        }
    }

    /**
     * 生成随机骰子点数（用于测试）
     * 
     * @return array 包含三个随机骰子点数的数组
     */
    public function generateRandomDices(): array
    {
        return [
            'dice1' => rand(1, 6),
            'dice2' => rand(1, 6), 
            'dice3' => rand(1, 6)
        ];
    }

    /**
     * 验证游戏结果的完整性
     * 
     * @param array $gameResult 游戏结果
     * @return bool 结果是否完整且合法
     */
    public function validateGameResult(array $gameResult): bool
    {
        $requiredFields = [
            'dice1', 'dice2', 'dice3', 'total_points',
            'is_big', 'is_small', 'is_odd', 'is_even',
            'winning_bets'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($gameResult[$field])) {
                LogHelper::error("游戏结果缺少必要字段: {$field}");
                return false;
            }
        }

        // 验证骰子点数
        try {
            $this->validateDiceValues(
                $gameResult['dice1'],
                $gameResult['dice2'], 
                $gameResult['dice3']
            );
        } catch (\InvalidArgumentException $e) {
            LogHelper::error("游戏结果骰子点数不合法", ['error' => $e->getMessage()]);
            return false;
        }

        // 验证总点数
        $expectedTotal = $gameResult['dice1'] + $gameResult['dice2'] + $gameResult['dice3'];
        if ($gameResult['total_points'] !== $expectedTotal) {
            LogHelper::error("游戏结果总点数计算错误", [
                'expected' => $expectedTotal,
                'actual' => $gameResult['total_points']
            ]);
            return false;
        }

        return true;
    }

    /**
     * 格式化游戏结果为显示格式
     * 
     * @param array $gameResult 游戏结果
     * @return string 格式化的结果字符串
     */
    public function formatGameResult(array $gameResult): string
    {
        $dice1 = $gameResult['dice1'];
        $dice2 = $gameResult['dice2'];
        $dice3 = $gameResult['dice3'];
        $total = $gameResult['total_points'];
        
        $bigSmall = '';
        if ($gameResult['is_big']) {
            $bigSmall = '大';
        } elseif ($gameResult['is_small']) {
            $bigSmall = '小';
        } else {
            $bigSmall = '通杀';
        }
        
        $oddEven = $gameResult['is_odd'] ? '单' : '双';
        
        $special = '';
        if ($gameResult['has_triple']) {
            $special = " 三同号({$gameResult['triple_number']})";
        } elseif ($gameResult['has_pair']) {
            $pairNumbers = implode(',', $gameResult['pair_numbers']);
            $special = " 对子({$pairNumbers})";
        }
        
        return "骰子: {$dice1}-{$dice2}-{$dice3}, 总和: {$total}, {$bigSmall}{$oddEven}{$special}";
    }

    /**
     * 获取投注类型的中文名称
     * 
     * @param string $betType 投注类型标识
     * @return string 中文名称
     */
    public function getBetTypeName(string $betType): string
    {
        $betNames = [
            'small' => '小',
            'big' => '大', 
            'odd' => '单',
            'even' => '双',
            'any-triple' => '任意三同号'
        ];
        
        // 处理动态投注类型
        if (preg_match('/^total-(\d+)$/', $betType, $matches)) {
            return "总和{$matches[1]}";
        }
        
        if (preg_match('/^single-(\d)$/', $betType, $matches)) {
            return "单骰{$matches[1]}";
        }
        
        if (preg_match('/^pair-(\d)$/', $betType, $matches)) {
            return "对子{$matches[1]}";
        }
        
        if (preg_match('/^triple-(\d)$/', $betType, $matches)) {
            return "三同号{$matches[1]}";
        }
        
        if (preg_match('/^combo-(\d)-(\d)$/', $betType, $matches)) {
            return "组合{$matches[1]}-{$matches[2]}";
        }
        
        return $betNames[$betType] ?? $betType;
    }
}

/**
 * ========================================
 * 类使用说明和最佳实践
 * ========================================
 * 
 * 1. 主要使用流程：
 *    生成骰子结果 -> calculateGameResult() -> 获取完整计算结果
 *    验证投注 -> isBetWinning() -> 判断是否中奖
 *    计算赔付 -> calculatePayout() -> 获取赔付金额
 * 
 * 2. 骰宝规则要点：
 *    - 3个骰子，每个1-6点
 *    - 总和3和18为通杀大小
 *    - 单骰投注可能有多倍赔付
 *    - 组合投注需要两个不同数字都出现
 * 
 * 3. 概率计算：
 *    - 总共216种可能结果(6³)
 *    - 各投注类型有不同的理论概率
 *    - 庄家优势因投注类型而异
 * 
 * 4. 性能优化：
 *    - 结果计算使用数组操作，避免重复计算
 *    - 概率计算使用预计算值
 *    - 支持批量投注验证
 * 
 * 5. 扩展性：
 *    - 易于添加新的投注类型
 *    - 支持自定义赔率计算
 *    - 可扩展统计分析功能
 * 
 * 6. 错误处理：
 *    - 完整的参数验证
 *    - 详细的错误日志记录
 *    - 优雅的异常处理
 */