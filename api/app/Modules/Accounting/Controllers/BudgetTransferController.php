<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Models\ApprovalDelegation;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Enums\BudgetTransferStatus;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Resources\BudgetTransferResource;
use App\Modules\Accounting\Services\BudgetTransferService;
use App\Modules\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BudgetTransferController extends Controller
{
    public function __construct(
        private readonly BudgetTransferService $transfers,
    ) {}

    /** List transfers, newest first. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'status' => ['nullable', Rule::enum(BudgetTransferStatus::class)],
            'actionable' => 'nullable|boolean',
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $actionable = ! $actor->hasPermission('budgeting.view') || $request->boolean('actionable');
        $query = BudgetTransfer::with(['fromLine.account', 'fromLine.budget.department', 'toLine.account', 'toLine.budget.department', 'requester', 'approver', 'rejecter'])
            ->orderByDesc('created_at');
        if ($actionable) {
            $query->awaitingApprovalFor($actor);
        } elseif ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $transfers = $query->paginate($perPage);
        foreach ($transfers->items() as $transfer) {
            $this->setCanApprove($transfer, $request);
        }

        return response()->json([
            'success' => true,
            'data' => BudgetTransferResource::collection($transfers->items()),
            'error' => null,
            'meta' => [
                'page' => $transfers->currentPage(),
                'current_page' => $transfers->currentPage(),
                'last_page' => $transfers->lastPage(),
                'per_page' => $transfers->perPage(),
                'total' => $transfers->total(),
                'from' => $transfers->firstItem(),
                'to' => $transfers->lastItem(),
            ],
            'links' => [
                'first' => $transfers->url(1),
                'last' => $transfers->url($transfers->lastPage()),
                'prev' => $transfers->previousPageUrl(),
                'next' => $transfers->nextPageUrl(),
            ],
        ]);
    }

    /** Show a single transfer. */
    public function show(Request $request, BudgetTransfer $budgetTransfer): JsonResponse
    {
        $actor = $request->user();
        $canApprove = $actor instanceof User
            && ApprovalDelegation::canActForPermission($actor, 'budgeting.approve', [(int) $budgetTransfer->requested_by])
            && $this->transfers->canApprove($budgetTransfer, $actor);
        abort_unless($actor instanceof User && ($actor->hasPermission('budgeting.view') || $canApprove), 403);

        $budgetTransfer->load(['fromLine.account', 'fromLine.budget.department', 'toLine.account', 'toLine.budget.department', 'requester', 'approver', 'rejecter']);
        $budgetTransfer->setAttribute('can_approve', $canApprove);

        return response()->json([
            'success' => true,
            'data' => new BudgetTransferResource($budgetTransfer),
            'error' => null,
            'meta' => null,
        ]);
    }

    /** Request a transfer (maker). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_line_item_id' => 'required',
            'to_line_item_id' => 'required',
            'month' => 'required|string|size:3',
            'amount' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d+)?$/'],
            'reason' => 'required|string|min:5|max:1000',
        ], [
            'amount.regex' => 'The amount must be a plain decimal figure, e.g. 1000 or 1000.50.',
        ]);

        $transfer = $this->transfers->request([
            'from_line_item_id' => $this->decodeLineId($validated['from_line_item_id'], 'from_line_item_id'),
            'to_line_item_id' => $this->decodeLineId($validated['to_line_item_id'], 'to_line_item_id'),
            'month' => $validated['month'],
            'amount' => (string) $validated['amount'],
            'reason' => $validated['reason'],
        ], (int) auth()->id());
        $this->setCanApprove($transfer, $request);

        return response()->json([
            'success' => true,
            'data' => new BudgetTransferResource($transfer),
            'error' => null,
            'meta' => null,
        ], 201);
    }

    /** Approve a pending transfer and apply the movement (checker). */
    public function approve(Request $request, BudgetTransfer $budgetTransfer): JsonResponse
    {
        $transfer = $this->transfers->approve($budgetTransfer, (int) auth()->id());
        $this->setCanApprove($transfer, $request);

        return response()->json(['success' => true, 'data' => new BudgetTransferResource($transfer), 'error' => null, 'meta' => null]);
    }

    /** Reject a pending transfer; nothing moves. */
    public function reject(Request $request, BudgetTransfer $budgetTransfer): JsonResponse
    {
        $transfer = $this->transfers->reject($budgetTransfer, (int) auth()->id());
        $this->setCanApprove($transfer, $request);

        return response()->json(['success' => true, 'data' => new BudgetTransferResource($transfer), 'error' => null, 'meta' => null]);
    }

    private function decodeLineId(mixed $value, string $field): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int) $value;
            if ($id > 0) {
                return $id;
            }
        }

        $id = HashIdFilter::decode($value, BudgetLineItem::class);
        if ($id === null || $id < 1) {
            throw ValidationException::withMessages([$field => 'The selected identifier is invalid.']);
        }

        return $id;
    }

    private function setCanApprove(BudgetTransfer $transfer, Request $request): void
    {
        $actor = $request->user();
        $transfer->setAttribute(
            'can_approve',
            $actor instanceof User
                && ApprovalDelegation::canActForPermission($actor, 'budgeting.approve', [(int) $transfer->requested_by])
                && $this->transfers->canApprove($transfer, $actor),
        );
    }
}
