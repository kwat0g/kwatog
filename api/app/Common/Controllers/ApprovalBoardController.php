<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Services\ApprovalBoardService;
use App\Common\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Series F — Task F2. Approvals Kanban board.
 *
 * GET /api/v1/approvals/board?type=leave|pr|po|loan|payroll
 */
class ApprovalBoardController
{
    public function __construct(
        private readonly ApprovalBoardService $service,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:leave,pr,po,loan,payroll'],
            'pending_limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'history_limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $type = $request->query('type');
        $pendingLimit = (int) $request->integer('pending_limit', 100);
        $historyLimit = (int) $request->integer('history_limit', 50);
        $board = $this->service->board(
            $request->user(),
            is_string($type) ? $type : null,
            $pendingLimit,
            $historyLimit,
        );

        return response()->json(['data' => $board]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'kinds' => $this->service->kindOptions(),
            'overdue_hours' => $this->settings->requiredInt('approvals.reminder_hours', 1),
        ]]);
    }
}
