<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\AdminDashboard\ReadAdminDashboardSummary;
use App\Application\AdminDirectory\RunAdminDirectorySnapshot;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(
        RunAdminDirectorySnapshot $snapshot,
        ReadAdminDashboardSummary $summary,
    ): JsonResponse {
        return ApiResponse::success(
            $snapshot->execute(
                fn (Connection $connection): array => $summary->read($connection)
            )
        );
    }
}
