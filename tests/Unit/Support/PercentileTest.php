<?php

declare(strict_types=1);

use App\Support\Percentile;

test('nearest rank picks the value at position CEIL(p × n)', function (array $frequencies, float $p, ?int $expected) {
    expect(Percentile::nearestRank($frequencies, $p))->toBe($expected);
})->with([
    // 10, 20, … 100: median = 5th = 50, p90 = 9th = 90.
    'median of ten' => [array_fill_keys(range(10, 100, 10), 1), 0.5, 50],
    'p90 of ten' => [array_fill_keys(range(10, 100, 10), 1), 0.9, 90],
    // 5, 7, 100: median = CEIL(1.5) = 2nd = 7, p90 = CEIL(2.7) = 3rd = 100.
    'median of three' => [[100 => 1, 5 => 1, 7 => 1], 0.5, 7],
    'p90 of three' => [[100 => 1, 5 => 1, 7 => 1], 0.9, 100],
    // Repeated values: 30 ×4, 60 ×1 → p90 = CEIL(4.5) = 5th = 60, median = 3rd = 30.
    'repeats, median' => [[30 => 4, 60 => 1], 0.5, 30],
    'repeats, p90' => [[30 => 4, 60 => 1], 0.9, 60],
    'single value' => [[42 => 1], 0.9, 42],
    'no observations' => [[], 0.5, null],
]);
