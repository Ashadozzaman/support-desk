<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route alias `ai.enabled`, for every AI endpoint (Steps 23–24). With AI
 * off the endpoints don't exist as far as a client can tell: 404, not 403.
 * AiGuard checks the same flag again before any provider call.
 */
class EnsureAiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('ai.enabled'), 404);

        return $next($request);
    }
}
