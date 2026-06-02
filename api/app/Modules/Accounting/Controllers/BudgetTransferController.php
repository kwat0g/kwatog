<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Resources\BudgetTransferResource;
use App\Modules\Accounting\Services\BudgetTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BudgetTransferController extends Controller
{
    public function __construct(
        private readonly BudgetTransferService $transferService,
    ) {}

    /**
     * List budget transfers.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BudgetTransfer::with([
            'fromLineItem.account', 'toLineItem.account',
            'requestedBy', 'approvedBy',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $transfers = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => BudgetTransferResource::collection($transfers->items()),
            'error'   => null,
            'meta'    => [
                'page'     => $transfers->currentPage(),
                'per_page' => $transfers->perPage(),
                'total'    => $transfers->total(),
            ],
        ]);
    }

    /**
     * Show a single transfer.
     */
    public function show(BudgetTransfer $transfer): JsonResponse
    {
        $transfer->load(['fromLineItem.account', 'toLineItem.account', 'requestedBy', 'approvedBy']);

        return response()->json([
            'success' => true,
            'data'    => new BudgetTransferResource($transfer),
            'error'   => null,
            'meta'    => null,
        ]);
    }

    /**
     * Request a budget transfer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_budget_line_id' => 'required|exists:budget_line_items,id',
            'to_budget_line_id'   => 'required|exists:budget_line_items,id',
            'amount'              => 'required|numeric|min:0.01',
            'reason'              => 'required|string|max:500',
        ]);

        try {
            $transfer = $this->transferService->request(
                (int) $validated['from_budget_line_id'],
                (int) $validated['to_budget_line_id'],
                (float) $validated['amount'],
                $validated['reason'],
                auth()->id(),
            );

            return response()->json([
                'success' => true,
                'data'    => new BudgetTransferResource($transfer->load(['fromLineItem.account', 'toLineItem.account', 'requestedBy'])),
                'error'   => null,
                'meta'    => null,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => $e->getMessage(),
                'meta'    => null,
            ], 422);
        }
    }

    /**
     * Approve a pending transfer.
     */
    public function approve(BudgetTransfer $transfer): JsonResponse
    {
        try {
            $transfer = $this->transferService->approve($transfer, auth()->id());

            return response()->json([
                'success' => true,
                'data'    => new BudgetTransferResource($transfer->load(['fromLineItem.account', 'toLineItem.account', 'approvedBy'])),
                'error'   => null,
                'meta'    => null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => $e->getMessage(),
                'meta'    => null,
            ], 422);
        }
    }

    /**
     * Reject a pending transfer.
     */
    public function reject(BudgetTransfer $transfer): JsonResponse
    {
        $transfer = $this->transferService->reject($transfer);

        return response()->json([
            'success' => true,
            'data'    => new BudgetTransferResource($transfer),
            'error'   => null,
            'meta'    => null,
        ]);
    }
}
