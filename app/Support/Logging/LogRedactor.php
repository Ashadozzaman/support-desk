<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns values that may carry personal data into something safe to log
 * (ENHANCEMENTS.md §2.1 "never log").
 */
class LogRedactor
{
    /**
     * Keyed hash: lets you see "the same email failed 20 times" without the
     * email itself. A plain sha256 could be reversed by hashing a list of
     * likely addresses; the app key prevents that.
     */
    public static function email(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        return hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key'));
    }

    /**
     * A QueryException message embeds the SQL *with bindings* (ticket text,
     * emails), so only the error code and the placeholder SQL are kept.
     */
    public static function exceptionMessage(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return "SQLSTATE[{$e->getCode()}] on: {$e->getSql()}";
        }

        return Str::limit($e->getMessage(), 500);
    }
}
