<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(
                'unauthenticated',
                'Authentication is required.',
                401,
            );
        }

        if ($user->status !== 'active') {
            return ApiResponse::error(
                'account_disabled',
                'This account is disabled.',
                403,
            );
        }

        return $next($request);
    }
}
