<?php

declare(strict_types=1);

namespace App\Support;

class Percentile
{
    /**
     * Nearest-rank percentile from a frequency table: the value at position
     * CEIL(p × n) of the sorted observations — always a real observed
     * value, no interpolation. Working from value => count (what a
     * `GROUP BY minutes` returns) avoids sorting every row the way a
     * ROW_NUMBER() window would: at ~1M tickets that sort cost 3-13s.
     *
     * @param  array<int, int>  $frequencies  observed value => how many times
     * @param  float  $p  0 < p <= 1, e.g. 0.9 for p90
     */
    public static function nearestRank(array $frequencies, float $p): ?int
    {
        $total = array_sum($frequencies);

        if ($total === 0) {
            return null;
        }

        ksort($frequencies);
        $rank = (int) ceil($p * $total);
        $seen = 0;

        foreach ($frequencies as $value => $count) {
            $seen += $count;

            if ($seen >= $rank) {
                return $value;
            }
        }

        return array_key_last($frequencies);
    }
}
