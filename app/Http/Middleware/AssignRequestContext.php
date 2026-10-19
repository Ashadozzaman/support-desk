<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for logs (ENHANCEMENTS.md §2.1). Anything put into Context
 * is added to every log line and is carried into jobs queued during this
 * request, so one id traces HTTP → job → notification.
 */
class AssignRequestContext
{
    public const HEADER = 'X-Request-Id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->incomingId($request) ?? (string) Str::uuid();

        Context::add('request_id', $requestId);
        Context::add('user_id', $request->user()?->id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * Reuse the id from a proxy/load balancer so its logs and ours line up —
     * but only a short, safe token: the value is copied into every log line.
     */
    private function incomingId(Request $request): ?string
    {
        $id = $request->headers->get(self::HEADER);

        return is_string($id) && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $id) === 1 ? $id : null;
    }
}
