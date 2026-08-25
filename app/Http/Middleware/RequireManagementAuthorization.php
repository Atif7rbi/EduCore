<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireManagementAuthorization
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::error(
                'unauthenticated',
                'Authentication is required.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if (! $user->isActive()) {
            return ApiResponse::error(
                'account_disabled',
                'This account is disabled.',
                Response::HTTP_FORBIDDEN,
            );
        }

        if (! $user->isAdmin()) {
            return ApiResponse::error(
                'management_forbidden',
                'Management access is not permitted.',
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
