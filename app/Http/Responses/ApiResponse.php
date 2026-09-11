<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        mixed $data,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'data' => $data,
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        ?array $details = null,
    ): JsonResponse {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }
}
