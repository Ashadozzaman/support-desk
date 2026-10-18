<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

class Csv
{
    /**
     * Spreadsheet apps execute a cell starting with = + - @ (and tab / CR)
     * as a formula — "CSV injection". Any text that can come from a user
     * (category or agent names here) is prefixed with ' so it's shown, not
     * run. Numbers are left alone so -5 stays a number.
     */
    public static function safeCell(string|int|float|null $value): string|int|float
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * One RFC 4180 row (CRLF, same as the existing import-error and agent
     * daily exports, so Excel doesn't read the file as one line).
     *
     * @param  resource  $handle
     * @param  array<int, string|int|float|null>  $row
     */
    public static function writeRow($handle, array $row): void
    {
        fputcsv($handle, array_map(self::safeCell(...), $row), ',', '"', '\\', "\r\n");
    }

    /**
     * fopen() that fails loudly — a silent false would write nothing and
     * still report the export as done.
     *
     * @return resource
     */
    public static function open(string $path, string $mode = 'w')
    {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path} for writing.");
        }

        return $handle;
    }
}
