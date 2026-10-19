<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API clients don't always send `Accept: application/json`. Without it a
 * failed FormRequest redirects back and the `verified` middleware redirects
 * to the verify page — both meaningless to a script. This makes every
 * /api response JSON, including validation errors.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
