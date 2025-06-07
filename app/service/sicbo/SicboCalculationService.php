<?php

namespace app\service\sicbo;

/**
 * 骰宝游戏计算服务类 - 极简版
 * 只保留核心计算功能
 */
class SicboCalculationService
{
    /**
     * 执行完整的骰宝游戏计算
     * 
     * @param int $dice1 第一个骰子点数(1-6)
     * @param int $dice2 第二个骰子点数(1-6)
     * @param int $dice3 第三个骰子点数(1-6)
     * @return array 完整的游戏计算结果
     */
    public function calculateGameResult(int $dice1, int $dice2, int $dice3): array
    {
        // 验证骰子点数
        $this->validateDiceValues($dice1, $dice2, $dice3);

        // 基础计算
        $totalPoints = $dice1 + $dice2 + $dice3;
        $isBig = $totalPoints >= 11 && $totalPoints <= 17;
        $isSmall = $totalPoints >= 4 && $totalPoints <= 10;
        $isOdd = ($totalPoints % 2) === 1;
        
        // 特殊组合检查
        $dices = [$dice1, $dice2, $dice3];
        $diceCounts = array_count_values($dices);
        
        // 三同号检查
        $hasTriple = in_array(3, $diceCounts);
        $tripleNumber = $hasTriple ? array_search(3, $diceCounts) : null;
        
        // 对子检查
        $hasPair = in_array(2, $diceCounts);
        $pairNumbers = [];
        foreach ($diceCounts as $dice => $count) {
            if ($count === 2) {
                $pairNumbers[] = $dice;
            }
        }
        
        // 计算中奖投注类型
        $winningBets = $this->calculateWinningBets($dice1, $dice2, $dice3, $totalPoints, $isBig, $isSmall, $isOdd, $hasTriple, $tripleNumber, $hasPair, $pairNumbers, $diceCounts);

        return [
            'dice1' => $dice1,
            'dice2' => $dice2,
            'dice3' => $dice3,
            'total_points' => $totalPoints,
            'is_big' => $isBig,
            'is_small' => $isSmall,
            'is_odd' => $isOdd,
            'is_even' => !$isOdd,
            'has_triple' => $hasTriple,
            'triple_number' => $tripleNumber,
            'has_pair' => $hasPair,
            'pair_numbers' => $pairNumbers,
            'single_counts' => $diceCounts,
            'winning_bets' => $winningBets
        ];
    }

    /**
     * 计算所有中奖的投注类型
     */
    private function calculateWinningBets($dice1, $dice2, $dice3, $totalPoints, $isBig, $isSmall, $isOdd, $hasTriple, $tripleNumber, $hasPair, $pairNumbers, $diceCounts): array
    {
        $winningBets = [];

        // 大小投注
        if ($isBig) $winningBets[] = 'big';
        if ($isSmall) $winningBets[] = 'small';

        // 单双投注
        if ($isOdd) {
            $winningBets[] = 'odd';
        } else {
            $winningBets[] = 'even';
        }

        // 总和投注
        $winningBets[] = "total-{$totalPoints}";

        // 单骰投注
        foreach ($diceCounts as $dice => $count) {
            $winningBets[] = "single-{$dice}";
        }

        // 对子投注
        if ($hasPair) {
            foreach ($pairNumbers as $pairNumber) {
                $winningBets[] = "pair-{$pairNumber}";
            }
        }

        // 三同号投注
        if ($hasTriple) {
            $winningBets[] = "triple-{$tripleNumber}";
            $winningBets[] = "any-triple";
        }

        // 组合投注
        $uniqueDices = array_keys($diceCounts);
        if (count($uniqueDices) >= 2) {
            sort($uniqueDices);
            for ($i = 0; $i < count($uniqueDices); $i++) {
                for ($j = $i + 1; $j < count($uniqueDices); $j++) {
                    $winningBets[] = "combo-{$uniqueDices[$i]}-{$uniqueDices[$j]}";
                }
            }
        }

        return array_unique($winningBets);
    }

    /**
     * 验证骰子点数的合法性
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
     * 验证用户投注是否中奖
     */
    public function isBetWinning(string $betType, array $gameResult): bool
    {
        return in_array($betType, $gameResult['winning_bets'] ?? []);
    }
}