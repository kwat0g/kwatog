<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Services\ApprovalBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Series F — Task F2. Approvals Kanban board.
 *
 * GET /api/v1/approvals/board?type=leave|pr|po|loan|payroll
 */
class ApprovalBoardController
{
    public function __construct(private readonly ApprovalBoardService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:leave,pr,po,loan,payroll'],
        ]);

        $type = $request->query('type');
        $board = $this->service->board($request->user(), is_string($type) ? $type : null);

        return response()->json(['data' => $board]);
    }
}
