<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireManagementAuthorization
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $authenticatedUser = $request->user();

        if (! $authenticatedUser instanceof User) {
            return ApiResponse::error(
                'unauthenticated',
                'Authentication is required.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $user = User::query()->find(
            $authenticatedUser->getAuthIdentifier()
        );

        if ($user === null) {
            Auth::guard('web')->logout();

            return ApiResponse::error(
                'unauthenticated',
                'Authentication is required.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        Auth::guard('web')->setUser($user);

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
