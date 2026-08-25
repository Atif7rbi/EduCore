<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireManagementAuthorization
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        return ApiResponse::error(
            'management_authorization_required',
            'Management authorization is not available yet.',
            Response::HTTP_FORBIDDEN,
        );
    }
}
