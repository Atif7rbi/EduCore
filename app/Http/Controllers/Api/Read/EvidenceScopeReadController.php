<?php

namespace App\Http\Controllers\Api\Read;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EvidenceScope;
use Illuminate\Http\JsonResponse;

class EvidenceScopeReadController extends Controller
{
    public function index(): JsonResponse
    {
        $scopes = EvidenceScope::query()
            ->get()
            ->sortBy(
                fn (EvidenceScope $scope): string => ($scope->status === 'active'
                        ? '0'
                        : '1')
                    .'|'
                    .mb_strtolower(
                        $scope->label ?? ''
                    )
                    .'|'
                    .$scope->id
            )
            ->values()
            ->map(
                fn (EvidenceScope $scope): array => [
                    'id' => $scope->id,
                    'label' => $scope->label,
                    'description' => $scope->description,
                    'status' => $scope->status,
                    'definition_schema_version' => $scope
                        ->definition_schema_version,
                ]
            )
            ->all();

        return ApiResponse::success(
            $scopes
        );
    }
}
