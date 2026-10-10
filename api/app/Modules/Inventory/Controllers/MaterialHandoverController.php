<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\MaintenancePartRequest;
use App\Modules\Maintenance\Services\MaintenancePartRequestService;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Record physical handover of an existing issue; never post another movement. */
class MaterialHandoverController
{
    private const GROUP_SQL = "CASE WHEN stock_movements.reference_type IN ('work_order', 'WorkOrder') THEN 'wo:' || stock_movements.reference_id WHEN handover_slips.work_order_id IS NOT NULL THEN 'wo:' || handover_slips.work_order_id ELSE 'slip:' || handover_slips.id END";

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['maintenance_work_order_id' => 'nullable|string|prohibits:work_order_id', 'status' => ['nullable', Rule::in(['pending', 'handed_over', 'needs_review', 'all'])],
            'search' => 'nullable|string|max:200', 'work_order_id' => 'nullable|string',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        if ($request->filled('maintenance_work_order_id')) {
            return $this->maintenanceIndex($request);
        }
        $query = $this->groupQuery();
        if ($request->filled('work_order_id')) {
            $id = HashIdFilter::decode($data['work_order_id'], WorkOrder::class);
            if (! $id) {
                throw ValidationException::withMessages(['work_order_id' => 'Choose a valid work order.']);
            }
            $query->forWorkOrder($id);
        }
        if ($search = trim($data['search'] ?? '')) {
            $matches = $this->groupQuery()->join('items as handover_items', 'handover_items.id', '=', 'stock_movements.item_id')
                ->where(fn ($q) => $q->where('handover_items.code', 'ilike', '%'.$search.'%')
                    ->orWhere('handover_items.name', 'ilike', '%'.$search.'%')->orWhere('handover_wos.wo_number', 'ilike', '%'.$search.'%')
                    ->orWhere('handover_slips.slip_number', 'ilike', '%'.$search.'%'));
            $query->whereIn(DB::raw(self::GROUP_SQL), $matches->selectRaw(self::GROUP_SQL));
        }
        $balance = StockMovement::HANDOVER_RETURNED_SQL;
        $pending = "NOT EXISTS (SELECT 1 FROM material_handovers WHERE material_handovers.stock_movement_id = stock_movements.id) AND stock_movements.quantity > {$balance}";
        $eligible = "(handover_wos.id IS NULL OR handover_wos.status IN ('confirmed', 'in_progress', 'paused')) AND (handover_slips.id IS NULL OR handover_slips.status = 'issued')";
        $grouped = $query->selectRaw(self::GROUP_SQL.' AS group_key')
            ->selectRaw("MAX(handover_wos.id) AS work_order_id, MAX(CASE WHEN handover_wos.id IS NULL THEN handover_slips.id END) AS material_issue_id,
                COALESCE(MAX(handover_wos.wo_number), MAX(handover_slips.slip_number)) AS label,
                COUNT(*) AS total_lines, MAX(stock_movements.created_at) AS latest_issue_at,
                SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) AS pending_lines,
                SUM(CASE WHEN EXISTS (SELECT 1 FROM material_handovers WHERE material_handovers.stock_movement_id = stock_movements.id) THEN 1 ELSE 0 END) AS handed_over_lines,
                SUM(CASE WHEN {$pending} AND NOT ({$eligible}) THEN 1 ELSE 0 END) AS review_lines")
            ->groupByRaw(self::GROUP_SQL);
        $status = $request->has('status') ? ($data['status'] ?? 'all') : 'pending';
        if ($status === 'pending') {
            $grouped->havingRaw("SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) > 0 AND SUM(CASE WHEN {$pending} AND NOT ({$eligible}) THEN 1 ELSE 0 END) = 0");
        }
        if ($status === 'needs_review') {
            $grouped->havingRaw("SUM(CASE WHEN {$pending} AND NOT ({$eligible}) THEN 1 ELSE 0 END) > 0");
        }
        if ($status === 'handed_over') {
            $grouped->havingRaw("SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) = 0");
        }
        $grouped->selectRaw('NULL::bigint AS maintenance_work_order_id');
        if (! $request->filled('work_order_id')) {
            $grouped->unionAll($this->maintenanceGroups($request));
        }
        $page = DB::query()->fromSub($grouped, 'handover_groups')->orderByDesc('latest_issue_at')->orderBy('group_key')
            ->paginate($data['per_page'] ?? 25);
        $page->through(fn ($row) => $this->taskSummary($row));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()]]);
    }

    public function sources(Request $request): JsonResponse
    {
        if ($request->filled('maintenance_work_order_id')) {
            return $this->maintenanceSources($request);
        }
        $data = $request->validate(['work_order_id' => 'nullable|string|required_without:material_issue_id|prohibits:material_issue_id',
            'material_issue_id' => 'nullable|string|required_without:work_order_id|prohibits:work_order_id']);
        $wo = null;
        $slip = null;
        $query = StockMovement::query()->handoverSources()->withHandoverBalance()
            ->with(['item' => fn ($query) => $query->withTrashed(), 'fromLocation', 'handover.recorder', 'referenceSlip']);
        if ($request->filled('work_order_id')) {
            $id = HashIdFilter::decode($data['work_order_id'], WorkOrder::class);
            if (! $id) {
                throw ValidationException::withMessages(['work_order_id' => 'Choose a valid work order.']);
            }
            $wo = WorkOrder::findOrFail($id);
        } else {
            $id = HashIdFilter::decode($data['material_issue_id'], MaterialIssueSlip::class);
            if (! $id) {
                throw ValidationException::withMessages(['material_issue_id' => 'Choose a valid material slip.']);
            }
            $slip = MaterialIssueSlip::findOrFail($id);
            $wo = $slip->workOrder;
        }
        if ($wo) {
            $query->forWorkOrder($wo->id);
        } else {
            $query->where('reference_type', 'material_issue_slip')->where('reference_id', $slip->id);
        }
        $lines = $query->orderBy('id')->get()->map(function ($source) use ($wo) {
            $open = (! $wo || in_array($wo->status->value, ['confirmed', 'in_progress', 'paused'], true))
                && ($source->reference_type !== 'material_issue_slip' || $source->referenceSlip?->status === MaterialIssueStatus::Issued);

            return ['movement_id' => $source->hash_id, 'item' => ['id' => $source->item->hash_id, 'code' => $source->item->code,
                'name' => $source->item->name, 'unit_of_measure' => $source->item->unit_of_measure],
                'location' => $source->fromLocation ? ['id' => $source->fromLocation->hash_id, 'code' => $source->fromLocation->code] : null,
                'lot_number' => $source->lot_number, 'issued_quantity' => (string) $source->quantity,
                'returned_quantity' => (string) $source->returned_quantity, 'remaining_quantity' => (string) $source->remaining_quantity,
                'eligible' => $open && ! $source->handover && bccomp((string) $source->remaining_quantity, '0', 3) > 0,
                'handover' => $source->handover ? $this->serialize($source->handover) : null, 'issued_at' => $source->created_at?->toIso8601String()];
        });
        $pending = $lines->filter(fn ($line) => ! $line['handover'] && bccomp($line['remaining_quantity'], '0', 3) > 0);
        $review = $pending->contains(fn ($line) => ! $line['eligible']);
        $recipients = $this->recipients($wo)->get()->map(fn ($employee) => ['value' => $employee->hash_id, 'label' => $employee->full_name]);
        $default = $wo ? Employee::find($wo->crews()->whereNull('ended_at')->latest('id')->value('recorder_employee_id'))?->hash_id : null;

        return response()->json(['data' => ['id' => ($wo ? 'wo:'.$wo->hash_id : 'slip:'.$slip->hash_id),
            'work_order_id' => $wo?->hash_id, 'material_issue_id' => $wo ? null : $slip->hash_id,
            'label' => $wo?->wo_number ?? $slip->slip_number, 'status' => $review ? 'needs_review' : ($pending->isNotEmpty() ? 'pending' : 'handed_over'),
            'total_lines' => $lines->count(), 'handed_over_lines' => $lines->whereNotNull('handover')->count(), 'pending_lines' => $pending->count(),
            'latest_issue_at' => $lines->max('issued_at'), 'sources' => $lines->values(), 'recipients' => $recipients,
            'default_recipient_id' => $recipients->contains('value', $default) ? $default : null]]);
    }

    private function groupQuery(): Builder
    {
        return StockMovement::query()->handoverSources()->where('stock_movements.reference_type', '!=', 'maintenance_work_order')
            ->leftJoin('material_issue_slips as handover_slips', fn ($join) => $join->on('handover_slips.id', '=', 'stock_movements.reference_id')->where('stock_movements.reference_type', 'material_issue_slip'))
            ->leftJoin('work_orders as handover_wos', fn ($join) => $join->on('handover_wos.id', '=', DB::raw("CASE WHEN stock_movements.reference_type IN ('work_order', 'WorkOrder') THEN stock_movements.reference_id ELSE handover_slips.work_order_id END")));
    }

    private function taskSummary(object $row): array
    {
        $maintenance = ($row->maintenance_work_order_id ?? null) ? app('hashids')->encode((int) $row->maintenance_work_order_id) : null;
        $wo = $row->work_order_id ? app('hashids')->encode((int) $row->work_order_id) : null;
        $slip = $row->material_issue_id ? app('hashids')->encode((int) $row->material_issue_id) : null;

        return ['id' => $maintenance ? 'maintenance:'.$maintenance : ($wo ? 'wo:'.$wo : 'slip:'.$slip),
            'maintenance_work_order_id' => $maintenance, 'work_order_id' => $wo, 'material_issue_id' => $slip,
            'label' => $row->label, 'status' => (int) $row->review_lines > 0 ? 'needs_review' : ((int) $row->pending_lines > 0 ? 'pending' : 'handed_over'),
            'total_lines' => (int) $row->total_lines, 'handed_over_lines' => (int) $row->handed_over_lines,
            'pending_lines' => (int) $row->pending_lines, 'latest_issue_at' => $row->latest_issue_at ? Carbon::parse($row->latest_issue_at)->toIso8601String() : null];
    }

    public function options(StockMovement $stockMovement): JsonResponse
    {
        if ($stockMovement->reference_type === 'maintenance_work_order') {
            $order = $stockMovement->referenceMaintenanceWorkOrder;
            app(MaintenancePartRequestService::class)->assertOpen($order);
            $recipient = app(MaintenancePartRequestService::class)->recipient($order, $order->assigned_to);
            return response()->json(['data' => ['quantity' => $this->remaining($stockMovement), 'unit_of_measure' => $stockMovement->item?->unit_of_measure,
                'default_recipient_id' => $recipient->hash_id, 'recipients' => [['value' => $recipient->hash_id, 'label' => $recipient->full_name]]]]);
        }
        [$wo, $slip] = $this->context($stockMovement);
        $this->assertOpen($wo, $slip);

        return response()->json(['data' => [
            'quantity' => $this->remaining($stockMovement),
            'unit_of_measure' => $stockMovement->item?->unit_of_measure,
            'default_recipient_id' => ($wo ? Employee::find($wo->crews()->whereNull('ended_at')->latest('id')->value('recorder_employee_id'))?->hash_id : null),
            'recipients' => $this->recipients($wo)->get()->map(fn ($employee) => [
                'value' => $employee->hash_id, 'label' => $employee->full_name,
            ]),
        ]]);
    }

    public function store(Request $request, StockMovement $stockMovement): JsonResponse
    {
        $data = $request->validate([
            'received_by_employee_id' => 'required|string',
            'expected_quantity' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,3})?$/'],
        ]);
        $recipientId = HashIdFilter::decode($data['received_by_employee_id'], Employee::class);
        if (! $recipientId) {
            throw ValidationException::withMessages(['received_by_employee_id' => 'Choose a valid recipient.']);
        }
        if ($stockMovement->reference_type === 'maintenance_work_order') {
            return $this->storeMaintenanceMovement($request, $stockMovement, $recipientId, $data['expected_quantity']);
        }
        [$snapshotWo, $snapshotSlip] = $this->context($stockMovement);
        $handover = DB::transaction(function () use ($snapshotWo, $snapshotSlip, $stockMovement, $recipientId, $data, $request) {
            // Same lock order as issue cancellation and returns.
            $wo = $snapshotWo ? WorkOrder::query()->lockForUpdate()->findOrFail($snapshotWo->id) : null;
            $slip = $snapshotSlip ? MaterialIssueSlip::query()->lockForUpdate()->findOrFail($snapshotSlip->id) : null;
            $source = StockMovement::query()->lockForUpdate()->findOrFail($stockMovement->id);
            $existing = $source->handover()->first();
            if ($existing) {
                abort_if($existing->received_by_employee_id !== $recipientId
                    || bccomp((string) $existing->quantity, $data['expected_quantity'], 3) !== 0, 409,
                    'Handover was already recorded with different details. Reload the issue.');

                return $existing;
            }
            $this->assertOpen($wo, $slip);
            $recipient = $this->recipients($wo)->whereKey($recipientId)->first();
            if (! $recipient) {
                throw ValidationException::withMessages(['received_by_employee_id' => 'Choose an active assigned Production worker.']);
            }
            $remaining = $this->remaining($source);
            abort_if(bccomp($remaining, '0', 3) <= 0 || bccomp($remaining, $data['expected_quantity'], 3) !== 0,
                409, 'The unreturned issue quantity changed. Reload the handover details.');

            $handover = MaterialHandover::create([
                'stock_movement_id' => $source->id, 'received_by_employee_id' => $recipientId,
                'recipient_name' => $recipient->full_name, 'recorded_by' => $request->user()->id,
                'quantity' => $remaining, 'handed_over_at' => now(),
            ]);
            if ($wo) {
                app(WorkOrderService::class)->autoFinishIfTargetReached($wo);
            }

            return $handover;
        });

        return response()->json(['data' => $this->serialize($handover)]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'received_by_employee_id' => 'required|string',
            'sources' => 'required|array|min:1|max:100',
            'sources.*.movement_id' => 'required|string|distinct',
            'sources.*.expected_quantity' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,3})?$/'],
        ]);
        $sources = collect($data['sources'])->map(function ($line) {
            $isRequest = str_starts_with($line['movement_id'], 'request:');
            $model = $isRequest ? MaintenancePartRequest::class : StockMovement::class;
            $id = HashIdFilter::decode($isRequest ? substr($line['movement_id'], 8) : $line['movement_id'], $model);
            abort_unless($id, 422, 'Choose a valid handover source.');
            return ['movement' => $model::query()->findOrFail($id), 'quantity' => $line['expected_quantity']];
        })->sortBy(fn ($line) => $line['movement']->id)->values();
        $contexts = $sources->map(function ($line) {
            if ($line['movement'] instanceof MaintenancePartRequest) {
                return 'maintenance:'.$line['movement']->work_order_id;
            }
            if ($line['movement']->reference_type === 'maintenance_work_order') {
                return 'maintenance:'.$line['movement']->reference_id;
            }
            [$wo, $slip] = $this->context($line['movement']);
            return $wo ? 'wo:'.$wo->id : 'slip:'.$slip?->id;
        })->unique();
        abort_unless($contexts->count() === 1, 422, 'Confirm material from one work order or slip at a time.');
        $records = DB::transaction(function () use ($sources, $data, $request) {
            return $sources->map(function ($line) use ($data, $request) {
                $single = clone $request;
                $single->replace([
                    'received_by_employee_id' => $data['received_by_employee_id'],
                    'expected_quantity' => $line['quantity'],
                ]);

                return ($line['movement'] instanceof MaintenancePartRequest
                    ? $this->confirmMaintenanceRequest($single, $line['movement'])
                    : $this->store($single, $line['movement']))->getData(true)['data'];
            });
        });

        return response()->json(['data' => $records]);
    }

    public function confirmMaintenanceRequest(Request $request, MaintenancePartRequest $partRequest): JsonResponse
    {
        $data = $request->validate(['received_by_employee_id' => 'required|string',
            'expected_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3']]);
        $id = HashIdFilter::decode($data['received_by_employee_id'], Employee::class);
        if (! $id) {
            throw ValidationException::withMessages(['received_by_employee_id' => 'Choose a valid recipient.']);
        }
        $result = app(MaintenancePartRequestService::class)->confirm($partRequest, $id, (string) $data['expected_quantity'], $request->user());
        return response()->json(['data' => ['id' => $result->hash_id, 'status' => $result->status]]);
    }

    private function maintenanceGroups(Request $request): \Illuminate\Database\Query\Builder
    {
        // Both pending requests and issued sources count once; fulfilled requests are represented by their movements.
        $requests = DB::table('maintenance_part_requests')->where('status', 'requested')
            ->selectRaw("work_order_id, item_id, created_at, 1 AS pending, 0 AS handed_over");
        $issues = DB::table('stock_movements')->where('reference_type', 'maintenance_work_order')->where('movement_type', 'material_issue')
            ->whereNotNull('from_location_id')->whereNull('to_location_id')
            ->selectRaw("reference_id AS work_order_id, item_id, created_at,
                CASE WHEN NOT EXISTS (SELECT 1 FROM material_handovers WHERE stock_movement_id = stock_movements.id)
                    AND quantity > ".StockMovement::HANDOVER_RETURNED_SQL." THEN 1 ELSE 0 END AS pending,
                CASE WHEN EXISTS (SELECT 1 FROM material_handovers WHERE stock_movement_id = stock_movements.id) THEN 1 ELSE 0 END AS handed_over");
        $lines = $requests->unionAll($issues);
        $query = DB::query()->fromSub($lines, 'maintenance_lines')->join('maintenance_work_orders as mwo', 'mwo.id', '=', 'maintenance_lines.work_order_id');
        if ($request->filled('maintenance_work_order_id')) {
            $id = HashIdFilter::decode($request->input('maintenance_work_order_id'), MaintenanceWorkOrder::class);
            abort_unless($id, 422, 'Choose a valid maintenance order.');
            $query->where('mwo.id', $id);
        }
        if ($search = trim((string) $request->input('search', ''))) {
            $matchingItems = DB::table('items')->where(fn ($q) => $q->where('code', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%'))->select('id');
            $matchingOrders = DB::query()->fromSub(clone $lines, 'matching_lines')->whereIn('item_id', $matchingItems)->select('work_order_id');
            $query->where(fn ($q) => $q->where('mwo.mwo_number', 'ilike', '%'.$search.'%')->orWhereIn('mwo.id', $matchingOrders));
        }
        $open = "mwo.status = 'in_progress' AND mwo.repair_submitted_at IS NULL";
        $query->selectRaw("'maintenance:' || mwo.id AS group_key, NULL::bigint AS work_order_id, NULL::bigint AS material_issue_id,
            mwo.mwo_number AS label, COUNT(*) AS total_lines, MAX(maintenance_lines.created_at) AS latest_issue_at,
            SUM(pending) AS pending_lines, SUM(handed_over) AS handed_over_lines,
            SUM(CASE WHEN pending = 1 AND NOT ({$open}) THEN 1 ELSE 0 END) AS review_lines, mwo.id AS maintenance_work_order_id")
            ->groupBy('mwo.id');
        $status = $request->has('status') ? ($request->input('status') ?: 'all') : 'pending';
        if ($status === 'pending') { $query->havingRaw("SUM(pending) > 0 AND SUM(CASE WHEN pending = 1 AND NOT ({$open}) THEN 1 ELSE 0 END) = 0"); }
        if ($status === 'needs_review') { $query->havingRaw("SUM(CASE WHEN pending = 1 AND NOT ({$open}) THEN 1 ELSE 0 END) > 0"); }
        if ($status === 'handed_over') { $query->havingRaw('SUM(pending) = 0'); }
        return $query;
    }

    private function maintenanceIndex(Request $request): JsonResponse
    {
        $query = $this->maintenanceGroups($request);
        $page = DB::query()->fromSub($query, 'maintenance_groups')->orderByDesc('latest_issue_at')->paginate($request->input('per_page', 25));
        return response()->json(['data' => array_map(fn ($row) => $this->taskSummary($row), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'last_page' => $page->lastPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    private function maintenanceSources(Request $request): JsonResponse
    {
        $request->validate(['maintenance_work_order_id' => 'required|string|prohibits:work_order_id,material_issue_id']);
        $id = HashIdFilter::decode($request->input('maintenance_work_order_id'), MaintenanceWorkOrder::class);
        abort_unless($id, 422, 'Choose a valid maintenance order.');
        $order = MaintenanceWorkOrder::findOrFail($id);
        $open = $order->status->value === 'in_progress' && $order->repair_submitted_at === null;
        $recipient = null;
        try { $recipient = app(MaintenancePartRequestService::class)->recipient($order, $order->assigned_to); }
        catch (ValidationException) { /* Missing or inactive assignment keeps receipt unavailable. */ }
        $requests = $order->partRequests()->where('status', 'requested')->with('item', 'location')->get()->map(fn ($part) => [
            'movement_id' => 'request:'.$part->hash_id, 'request_id' => $part->hash_id,
            'item' => ['id' => $part->item->hash_id, 'code' => $part->item->code, 'name' => $part->item->name, 'unit_of_measure' => $part->item->unit_of_measure],
            'location' => ['id' => $part->location->hash_id, 'code' => $part->location->full_code], 'lot_number' => null,
            'issued_quantity' => '0.000', 'requested_quantity' => $part->quantity, 'returned_quantity' => '0.000', 'remaining_quantity' => $part->quantity,
            'eligible' => $open && $recipient !== null, 'handover' => null, 'issued_at' => $part->created_at->toISOString(),
        ]);
        $issues = StockMovement::where('reference_type', 'maintenance_work_order')->where('reference_id', $order->id)->handoverSources()
            ->withHandoverBalance()->with(['item' => fn ($q) => $q->withTrashed(), 'fromLocation', 'handover.recorder'])->orderBy('id')->get()->map(fn ($source) => [
                'movement_id' => $source->hash_id, 'request_id' => null,
                'item' => ['id' => $source->item->hash_id, 'code' => $source->item->code, 'name' => $source->item->name, 'unit_of_measure' => $source->item->unit_of_measure],
                'location' => $source->fromLocation ? ['id' => $source->fromLocation->hash_id, 'code' => $source->fromLocation->code] : null,
                'lot_number' => $source->lot_number, 'issued_quantity' => $source->quantity, 'returned_quantity' => (string) $source->returned_quantity,
                'remaining_quantity' => (string) $source->remaining_quantity, 'eligible' => $open && $recipient !== null && ! $source->handover && bccomp((string) $source->remaining_quantity, '0', 3) > 0,
                'handover' => $source->handover ? $this->serialize($source->handover) : null, 'issued_at' => $source->created_at?->toISOString(),
            ]);
        $sources = $requests->concat($issues)->values();
        $pending = $sources->filter(fn ($line) => ! $line['handover'] && bccomp($line['remaining_quantity'], '0', 3) > 0);
        return response()->json(['data' => ['id' => 'maintenance:'.$order->hash_id, 'maintenance_work_order_id' => $order->hash_id,
            'work_order_id' => null, 'material_issue_id' => null, 'label' => $order->mwo_number,
            'status' => $pending->contains(fn ($line) => ! $line['eligible']) ? 'needs_review' : ($pending->isNotEmpty() ? 'pending' : 'handed_over'),
            'total_lines' => $sources->count(), 'pending_lines' => $pending->count(), 'handed_over_lines' => $sources->whereNotNull('handover')->count(),
            'latest_issue_at' => $sources->max('issued_at'), 'sources' => $sources,
            'recipients' => $recipient ? [['value' => $recipient->hash_id, 'label' => $recipient->full_name]] : [], 'default_recipient_id' => $recipient?->hash_id]]);
    }

    private function storeMaintenanceMovement(Request $request, StockMovement $movement, int $recipientId, string $quantity): JsonResponse
    {
        $handover = DB::transaction(function () use ($request, $movement, $recipientId, $quantity) {
            $order = MaintenanceWorkOrder::lockForUpdate()->findOrFail($movement->reference_id);
            $movement = StockMovement::lockForUpdate()->findOrFail($movement->id);
            abort_unless($movement->movement_type === StockMovementType::MaterialIssue && $movement->from_location_id && ! $movement->to_location_id, 422);
            $existing = $movement->handover;
            if ($existing) {
                abort_if($existing->received_by_employee_id !== $recipientId || bccomp($existing->quantity, $quantity, 3) !== 0, 409, 'Handover was already recorded with different details.');
                return $existing;
            }
            $service = app(MaintenancePartRequestService::class);
            $service->assertOpen($order);
            $recipient = $service->recipient($order, $recipientId);
            $remaining = $this->remaining($movement);
            abort_if(bccomp($remaining, '0', 3) <= 0 || bccomp($remaining, $quantity, 3) !== 0, 409, 'Remaining quantity changed. Reload the handover.');
            return MaterialHandover::create(['stock_movement_id' => $movement->id, 'received_by_employee_id' => $recipient->id,
                'recipient_name' => $recipient->full_name, 'recorded_by' => $request->user()->id, 'quantity' => $remaining, 'handed_over_at' => now()]);
        });
        return response()->json(['data' => $this->serialize($handover)]);
    }

    private function context(StockMovement $source): array
    {
        abort_unless($source->movement_type === StockMovementType::MaterialIssue && $source->from_location_id && ! $source->to_location_id, 422,
            'Only an existing material issue can be handed over.');
        if (in_array($source->reference_type, ['work_order', 'WorkOrder'], true)) {
            $wo = WorkOrder::query()->find($source->reference_id);
            abort_unless($wo && $wo->materials()->where('item_id', $source->item_id)->exists(), 422, 'Issue has no valid work-order material plan.');

            return [$wo, null];
        }
        $slip = $source->reference_type === 'material_issue_slip' ? MaterialIssueSlip::query()->find($source->reference_id) : null;
        abort_unless($slip && $slip->items()->where('stock_movement_id', $source->id)->where('item_id', $source->item_id)->exists(), 422,
            'Issue has no valid material slip.');
        $wo = $slip->work_order_id ? WorkOrder::query()->find($slip->work_order_id) : null;
        abort_if($slip->work_order_id && ! $wo, 422, 'Linked work order is unavailable.');

        return [$wo, $slip];
    }

    private function assertOpen(?WorkOrder $wo, ?MaterialIssueSlip $slip): void
    {
        abort_if($slip && $slip->status !== MaterialIssueStatus::Issued, 409, 'Material slip is no longer issued.');
        abort_if($wo && ! in_array($wo->status->value, ['confirmed', 'in_progress', 'paused'], true), 409,
            'Record handover while the work order is confirmed or active.');
    }

    private function recipients(?WorkOrder $wo): Builder
    {
        $query = Employee::query()->where('status', 'active')->whereHas('department', fn ($q) => $q->where('code', 'PROD')->where('is_active', true));
        if ($wo) {
            $crew = $wo->crews()->whereNull('ended_at')->latest('id')->first();
            $query->whereIn('id', $crew ? $crew->members()->pluck('employee_id') : []);
        } else {
            $query->whereHas('user', fn ($q) => $q->where('is_active', true)->whereHas('role', fn ($role) => $role->where('slug', 'employee')));
        }

        return $query->orderBy('last_name')->orderBy('first_name');
    }

    private function remaining(StockMovement $source): string
    {
        $returned = StockMovement::query()->where('reference_type', 'stock_movement')->where('reference_id', $source->id)
            ->where('movement_type', StockMovementType::MaterialReturn->value)->sum('quantity');

        return bcsub((string) $source->quantity, (string) $returned, 3);
    }

    private function serialize(MaterialHandover $handover): array
    {
        return ['recipient_name' => $handover->recipient_name, 'quantity' => (string) $handover->quantity,
            'handed_over_at' => $handover->handed_over_at->toIso8601String(), 'recorded_by' => $handover->recorder?->name];
    }
}
