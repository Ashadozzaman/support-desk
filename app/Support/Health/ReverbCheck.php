<?php

declare(strict_types=1);

namespace App\Support\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Can the app reach the Reverb server it broadcasts to? A plain TCP connect
 * to the configured host/port (1 s timeout) — enough to tell "process down"
 * from "up". Registered with ->if(BROADCAST_CONNECTION === 'reverb') so CI
 * and tests (BROADCAST_CONNECTION=log) don't report a false failure.
 */
class ReverbCheck extends Check
{
    public function run(): Result
    {
        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port');

        $result = Result::make()->meta(['host' => $host, 'port' => $port]);

        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 1.0);

        if ($socket === false) {
            return $result->failed("Cannot connect to Reverb at {$host}:{$port} ({$errorMessage}).")->shortSummary('Unreachable');
        }

        fclose($socket);

        return $result->ok()->shortSummary('Reachable');
    }
}
