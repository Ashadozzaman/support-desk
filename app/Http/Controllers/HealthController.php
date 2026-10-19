<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Machine endpoint for uptime monitors / load balancers. 503 tells them the
 * app is up but not doing its job (scheduler stalled, queue backed up, …),
 * unlike /up which only proves PHP boots. Access: RequireHealthSecret.
 */
class HealthController extends Controller
{
    public function __construct(private readonly SystemHealthService $health) {}

    public function __invoke(): JsonResponse
    {
        $report = $this->health->run();

        return response()
            ->json($report->toArray(), $report->healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
