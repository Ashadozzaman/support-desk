<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /health is for uptime monitors, not people, so it's protected by a
 * shared secret header instead of a session. Used instead of spatie's own
 * RequiresSecretToken, which lets every request through when no secret is
 * configured and compares with `!==` (not constant-time). This fails closed.
 */
class RequireHealthSecret
{
    public const HEADER = 'X-Health-Secret';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('support.monitoring.health_secret');
        $given = (string) $request->headers->get(self::HEADER, '');

        abort_if($secret === '' || ! hash_equals($secret, $given), 401);

        return $next($request);
    }
}
