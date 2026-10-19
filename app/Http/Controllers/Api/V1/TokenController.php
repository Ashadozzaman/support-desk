<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueApiTokenRequest;
use App\Http\Resources\Api\ApiUserResource;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @tags Authentication
 */
class TokenController extends Controller
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * Issue a token.
     *
     * Exchange email + password (+ a TOTP `code` if two-factor is on) for a
     * bearer token. The token is returned once; send it as
     * `Authorization: Bearer <token>`.
     *
     * @unauthenticated
     */
    public function store(IssueApiTokenRequest $request): JsonResponse
    {
        $token = $this->tokens->issue($request->toData());

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Revoke the current token.
     */
    public function destroy(Request $request): Response
    {
        $this->tokens->revokeCurrent($request->user(), $request->ip(), $request->userAgent());

        return response()->noContent();
    }

    /**
     * Current user.
     *
     * The token owner with their roles and permissions.
     */
    public function me(Request $request): ApiUserResource
    {
        return new ApiUserResource($request->user());
    }
}
