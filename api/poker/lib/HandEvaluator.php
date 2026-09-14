<?php
require_once __DIR__ . '/Deck.php';

class HandEvaluator {

    public static function best(array $sevenCards): array {
        $best = null;
        foreach (self::combinations($sevenCards, 5) as $five) {
            $val = self::evaluate5($five);
            if ($best === null || self::cmp($val, $best) > 0) {
                $best = $val;
            }
        }
        return $best;
    }

    public static function cmp(array $a, array $b): int {
        $len = max(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $av = $a[$i] ?? 0;
            $bv = $b[$i] ?? 0;
            if ($av != $bv) return $av <=> $bv;
        }
        return 0;
    }

    private static function evaluate5(array $cards): array {
        $ranks = array_map(fn($c) => Deck::rankValue($c), $cards);
        $suits = array_map(fn($c) => Deck::suit($c), $cards);
        rsort($ranks);

        $isFlush = count(array_unique($suits)) === 1;

        $uniqueRanks = array_values(array_unique($ranks));
        rsort($uniqueRanks);
        $isStraight = false;
        $straightHigh = 0;
        if (count($uniqueRanks) === 5) {
            if ($uniqueRanks[0] - $uniqueRanks[4] === 4) {
                $isStraight = true;
                $straightHigh = $uniqueRanks[0];
            } elseif ($uniqueRanks === [14,5,4,3,2]) {
                $isStraight = true;
                $straightHigh = 5;
            }
        }

        $freq = array_count_values($ranks);
        $groups = [];
        foreach ($freq as $rank => $count) {
            $groups[] = [$rank, $count];
        }
        usort($groups, function($a, $b) {
            if ($a[1] !== $b[1]) return $b[1] - $a[1];
            return $b[0] - $a[0];
        });

        $counts = array_column($groups, 1);
        $tieRanks = array_column($groups, 0);

        if ($isStraight && $isFlush) return [8, $straightHigh];
        if ($counts[0] === 4) return [7, $tieRanks[0], $tieRanks[1]];
        if ($counts[0] === 3 && ($counts[1] ?? 0) === 2) return [6, $tieRanks[0], $tieRanks[1]];
        if ($isFlush) return array_merge([5], $ranks);
        if ($isStraight) return [4, $straightHigh];
        if ($counts[0] === 3) return array_merge([3, $tieRanks[0]], array_slice($tieRanks, 1));
        if ($counts[0] === 2 && ($counts[1] ?? 0) === 2) {
            $pairRanks = [$tieRanks[0], $tieRanks[1]];
            rsort($pairRanks);
            return [2, $pairRanks[0], $pairRanks[1], $tieRanks[2]];
        }
        if ($counts[0] === 2) return array_merge([1, $tieRanks[0]], array_slice($tieRanks, 1));
        return array_merge([0], $ranks);
    }

    private static function combinations(array $arr, int $k): array {
        $result = [];
        $n = count($arr);
        if ($k > $n) return $result;
        $indices = range(0, $k - 1);
        while (true) {
            $combo = [];
            foreach ($indices as $i) $combo[] = $arr[$i];
            $result[] = $combo;
            $i = $k - 1;
            while ($i >= 0 && $indices[$i] === $i + $n - $k) $i--;
            if ($i < 0) break;
            $indices[$i]++;
            for ($j = $i + 1; $j < $k; $j++) $indices[$j] = $indices[$j - 1] + 1;
        }
        return $result;
    }

    public static function categoryName(int $cat): string {
        $names = [0=>'Старшая карта',1=>'Пара',2=>'Две пары',3=>'Сет',4=>'Стрит',5=>'Флеш',6=>'Фулл-хаус',7=>'Каре',8=>'Стрит-флеш'];
        return $names[$cat] ?? '';
    }
}
