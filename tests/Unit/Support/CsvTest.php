<?php

declare(strict_types=1);

use App\Support\Csv;

test('cells that a spreadsheet would run as a formula are neutralised', function (mixed $value, mixed $expected) {
    expect(Csv::safeCell($value))->toBe($expected);
})->with([
    'formula' => ['=HYPERLINK("http://evil")', '\'=HYPERLINK("http://evil")'],
    'plus' => ['+1+2', "'+1+2"],
    'minus text' => ['-cmd', "'-cmd"],
    'at' => ['@SUM(A1)', "'@SUM(A1)"],
    'tab' => ["\t=1", "'\t=1"],
    'plain text' => ['Billing', 'Billing'],
    'negative number stays a number' => [-5, -5],
    'null' => [null, ''],
    'empty string' => ['', ''],
]);

test('writeRow escapes every cell and ends with CRLF', function () {
    $handle = fopen('php://memory', 'w+');
    Csv::writeRow($handle, ['=1+1', 'ok', 3]);
    rewind($handle);

    expect(stream_get_contents($handle))->toBe("'=1+1,ok,3\r\n");
});
