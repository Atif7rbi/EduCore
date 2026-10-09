<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTeacherAuthorization
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

        if (! $user->isTeacher()) {
            return ApiResponse::error(
                'teacher_forbidden',
                'Teacher access is not permitted.',
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
