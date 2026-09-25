<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\ReturnManagement\Enums\ReturnCaseStatus;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnCaseAttachment;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Shared case intake and discussion. Operational effects stay in their owning modules. */
class ReturnCaseService
{
    public function __construct(
        private readonly ReturnCaseSourceService $sources,
        private readonly DocumentSequenceService $sequences,
        private readonly NotificationService $notifications,
        private readonly ReturnCaseSettlementService $settlements,
    ) {}

    public function list(array $filters, ?int $customerId = null, ?int $vendorId = null): LengthAwarePaginator
    {
        return ReturnCase::query()->with(['customer', 'vendor', 'owner', 'delivery', 'purchaseOrder', 'goodsReceiptNote', 'returnRequest'])
            ->when($customerId !== null, fn ($q) => $q->where('type', 'customer')->where('customer_id', $customerId))
            ->when($vendorId !== null, fn ($q) => $q->where('type', 'supplier')->where('vendor_id', $vendorId))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(! empty($filters['search']), fn ($q) => $q->where(fn ($s) => $s->where('case_number', 'ilike', '%'.$filters['search'].'%')
                ->orWhere('description', 'ilike', '%'.$filters['search'].'%')
                ->orWhereHas('delivery', fn ($d) => $d->where('delivery_number', 'ilike', '%'.$filters['search'].'%'))
                ->orWhereHas('purchaseOrder', fn ($p) => $p->where('po_number', 'ilike', '%'.$filters['search'].'%'))
                ->orWhereHas('goodsReceiptNote', fn ($g) => $g->where('grn_number', 'ilike', '%'.$filters['search'].'%'))))
            ->latest('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
    }

    public function show(ReturnCase $case): ReturnCase
    {
        return $case->load([
            'customer', 'vendor', 'owner', 'delivery', 'purchaseOrder', 'goodsReceiptNote',
            'lines.product', 'lines.item', 'events', 'attachments.event',
            'returnRequest.items', 'returnRequest.creditNote', 'creditNote',
            'replacementSalesOrder', 'replacementPurchaseOrder', 'replacementDelivery', 'resolutionGoodsReceiptNote',
            'receiptAllocations.goodsReceiptNote', 'receiptAllocations.caseLine',
        ]);
    }

    public function create(array $data, User $by, ?CustomerPortalUser $portalUser = null): ReturnCase
    {
        $case = DB::transaction(function () use ($data, $by, $portalUser): ReturnCase {
            $source = $this->sources->resolve($data['source_kind'], $data['source_id'], $portalUser?->customer_id);
            $key = hash('sha256', ($portalUser ? 'customer:'.$portalUser->id : 'internal:'.$by->id).':'.$data['request_key']);
            $reportData = $data;
            unset($reportData['request_key']);
            $fingerprint = hash('sha256', json_encode($reportData, JSON_THROW_ON_ERROR));
            $existing = ReturnCase::query()->where('created_by', $by->id)->where('request_key', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This submission reference already belongs to different report details. Refresh the form before submitting another report.');
                }
                return $existing;
            }
            $duplicate = ReturnCase::query()->where('request_fingerprint', $fingerprint)
                ->whereNotIn('status', ['resolved', 'withdrawn'])->first();
            if ($duplicate) {
                return $duplicate;
            }
            $lines = $this->sources->prepareLines($source, $data['lines']);
            $customer = $source instanceof Delivery;
            $role = $customer ? 'customer_service_officer' : 'purchasing_officer';
            $owner = User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('slug', $role))->orderBy('id')->first();
            $case = new ReturnCase;
            $case->forceFill([
                'case_number' => $this->sequences->generate('return_case'),
                'type' => $customer ? 'customer' : 'supplier', 'status' => 'submitted',
                'customer_id' => $customer ? $source->salesOrder->customer_id : null,
                'vendor_id' => $customer ? null : $source->vendor_id,
                'delivery_id' => $customer ? $source->id : null,
                'purchase_order_id' => $customer ? null : ($source instanceof GoodsReceiptNote ? $source->purchase_order_id : $source->id),
                'goods_receipt_note_id' => $source instanceof GoodsReceiptNote ? $source->id : null,
                'created_by' => $by->id, 'customer_portal_user_id' => $portalUser?->id,
                'assigned_to' => $owner?->id, 'request_key' => $key, 'request_fingerprint' => $fingerprint,
                'description' => $data['description'], 'preferred_resolution' => $data['preferred_resolution'] ?? 'advice',
            ])->save();
            foreach ($lines as $line) {
                $record = new \App\Modules\ReturnManagement\Models\ReturnCaseLine;
                $record->forceFill(array_merge($line, ['return_case_id' => $case->id]))->save();
            }
            $actor = $portalUser ? ['type' => 'customer', 'id' => $portalUser->id, 'name' => $portalUser->name] : $this->internalActor($by);
            $this->event($case, 'submitted', 'Report submitted. '.($customer ? 'Customer Service' : 'Purchasing').' will review the quantities and next action.', $actor);
            DB::afterCommit(fn () => $this->notify($case, 'New problem report awaiting review'));

            return $case;
        }, 3);

        return $this->show($case);
    }

    /**
     * Create the customer-side Quality/billing hold for goods the customer
     * actually received but the driver declared damaged. Truck-retained and
     * unaccounted quantities are deliberately excluded from this claim.
     */
    public function createDeliveryAttemptDamageCase(
        Delivery $delivery,
        \App\Modules\SupplyChain\Models\DeliveryAttemptOutcome $outcome,
        User $by,
        bool $correction = false,
    ): ?ReturnCase {
        return DB::transaction(function () use ($delivery, $outcome, $by, $correction): ?ReturnCase {
            $outcome = \App\Modules\SupplyChain\Models\DeliveryAttemptOutcome::query()
                ->with(['items.deliveryItem.salesOrderItem.product'])
                ->lockForUpdate()
                ->findOrFail($outcome->id);
            $damageLines = $outcome->items->filter(
                static fn ($line): bool => bccomp((string) $line->customer_received_damaged_quantity, '0', 3) > 0,
            );
            $order = \App\Modules\CRM\Models\SalesOrder::query()->lockForUpdate()->findOrFail($delivery->sales_order_id);
            $key = hash('sha256', 'delivery-attempt-damaged:'.$outcome->id);
            $fingerprint = hash('sha256', json_encode($damageLines->map(static fn ($line): array => [
                'delivery_item_id' => (int) $line->delivery_item_id,
                'received' => (string) $line->customer_received_quantity,
                'damaged' => (string) $line->customer_received_damaged_quantity,
            ])->values()->all(), JSON_THROW_ON_ERROR));
            $existing = ReturnCase::query()->where('delivery_id', $delivery->id)->where('request_key', $key)->lockForUpdate()->first();
            if ($existing && hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                return $existing->load('lines');
            }
            if ($existing) {
                $correctedWithdrawal = $existing->status->value === 'withdrawn'
                    && $existing->events()->where('action', 'driver_report_corrected')->exists();
                if (! $correction || (! $correctedWithdrawal && $existing->status->value !== 'submitted') || $this->hasEffects($existing)) {
                    throw new BusinessRuleException('Customer Service has started reviewing the damage report. Coordinate the correction through that problem report before changing the driver quantities.');
                }
                $existing->forceFill(['request_fingerprint' => $fingerprint,
                    'status' => $damageLines->isEmpty() ? 'withdrawn' : 'submitted'])->save();
                $this->event($existing, 'driver_report_corrected', $damageLines->isEmpty()
                    ? 'The driver report was corrected before the depot count. No damaged customer-received goods remain in this report.'
                    : 'The driver corrected the received and damaged quantities before the depot count. The revised quantities need review.', $this->internalActor($by));
                if ($damageLines->isEmpty()) {
                    return $existing;
                }
                $existing->lines()->delete();
            } elseif ($damageLines->isEmpty()) {
                return null;
            }

            $owner = User::query()->where('is_active', true)
                ->whereHas('role', fn ($query) => $query->where('slug', 'customer_service_officer'))
                ->orderBy('id')->first();
            $case = $existing ?? new ReturnCase;
            $case->forceFill([
                'case_number' => $existing?->case_number ?? $this->sequences->generate('return_case'),
                'type' => 'customer',
                'status' => 'submitted',
                'customer_id' => $order->customer_id,
                'delivery_id' => $delivery->id,
                'created_by' => $existing?->created_by ?? $by->id,
                'assigned_to' => $existing?->assigned_to ?? $owner?->id,
                'preferred_resolution' => 'advice',
                'request_key' => $key,
                'request_fingerprint' => $fingerprint,
                'description' => "The customer accepted goods from delivery {$delivery->delivery_number}, but the driver reported damage. Review the received damaged quantities before confirming or billing this delivery.",
            ])->save();

            foreach ($damageLines as $outcomeLine) {
                $source = $outcomeLine->deliveryItem;
                $product = $source?->salesOrderItem?->product;
                if (! $source || ! $product) {
                    throw new BusinessRuleException('A customer damage hold requires the delivery line product lineage.');
                }
                $itemId = \App\Modules\Inventory\Models\Item::query()
                    ->where('code', $product->part_number)
                    ->where('item_type', 'finished_good')
                    ->value('id');
                $line = new \App\Modules\ReturnManagement\Models\ReturnCaseLine;
                $line->forceFill([
                    'return_case_id' => $case->id,
                    'source_delivery_item_id' => $source->id,
                    'product_id' => $product->id,
                    'item_id' => $itemId,
                    'description' => $product->name ?: $product->part_number,
                    'unit' => $product->unit_of_measure ?: 'pcs',
                    'expected_quantity' => $outcomeLine->customer_received_quantity,
                    'received_quantity' => $outcomeLine->customer_received_quantity,
                    'missing_quantity' => '0.000',
                    'defective_quantity' => $outcomeLine->customer_received_damaged_quantity,
                    'source_unit_price' => $source->unit_price,
                    'reason' => 'Driver-reported damaged customer-received goods.',
                ])->save();
            }

            $this->event(
                $case,
                'submitted',
                'Driver reported damage to goods accepted by the customer. Review the received quantities before confirmation or billing.',
                $this->internalActor($by),
            );
            DB::afterCommit(fn () => $this->notify($case, 'Customer damage report awaiting review'));
            return $case->load('lines');
        }, 3);
    }

    public function canReportNotArrived(Delivery $delivery): bool
    {
        return in_array($delivery->status->value, ['in_transit', 'return_pending', 'delivered'], true)
            || (in_array($delivery->status->value, ['scheduled', 'loading'], true)
                && $delivery->scheduled_date && $delivery->scheduled_date->startOfDay()->lte(today()));
    }

    /** A tracking inquiry has no claimed quantities or settlement budget. */
    public function reportNotArrived(Delivery $delivery, array $data, User $by, CustomerPortalUser $portalUser): ReturnCase
    {
        $key = hash('sha256', 'delivery-trace:'.$portalUser->id.':'.strtolower((string) $data['request_key']));
        $message = trim((string) ($data['message'] ?? '')) ?: 'This shipment has not arrived. Please check its status and tell us the next step.';
        $fingerprint = hash('sha256', json_encode([(int) $delivery->id, $message], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($delivery, $by, $portalUser, $key, $message, $fingerprint): ReturnCase {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            abort_unless((int) $delivery->salesOrder?->customer_id === (int) $portalUser->customer_id, 404);
            $replay = ReturnCase::query()->where('created_by', $by->id)->where('request_key', $key)->first();
            if ($replay) {
                if (! hash_equals((string) $replay->request_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This report request was already used with different details.');
                }
                return $this->show($replay);
            }
            if (! $this->canReportNotArrived($delivery)) {
                throw new BusinessRuleException('Tracking reports are available when the delivery is due or on its way. For a confirmed delivery, report a receipt problem.');
            }
            $open = ReturnCase::query()->where('delivery_id', $delivery->id)->where('intake_kind', 'delivery_trace')
                ->whereNotIn('status', ['resolved', 'withdrawn', 'rejected'])->first();
            if ($open) {
                return $this->show($open);
            }
            $owner = User::query()->where('is_active', true)
                ->whereHas('role', fn ($query) => $query->where('slug', 'customer_service_officer'))->orderBy('id')->first();
            $case = new ReturnCase;
            $case->forceFill([
                'case_number' => $this->sequences->generate('return_case'), 'type' => 'customer',
                'intake_kind' => 'delivery_trace', 'status' => 'submitted',
                'customer_id' => $portalUser->customer_id, 'customer_portal_user_id' => $portalUser->id,
                'delivery_id' => $delivery->id, 'created_by' => $by->id, 'assigned_to' => $owner?->id,
                'preferred_resolution' => 'advice', 'description' => $message,
                'request_key' => $key, 'request_fingerprint' => $fingerprint,
            ])->save();
            $this->event($case, 'submitted', 'Shipment not received. Customer Service will check with Dispatch and post an update.',
                ['type' => 'customer', 'id' => $portalUser->id, 'name' => $portalUser->name]);
            DB::afterCommit(function () use ($case): void {
                $this->notify($case, 'Customer is waiting for their shipment');
                $dispatch = User::query()->where('is_active', true)->whereHas('role.permissions', fn ($q) => $q->where('slug', 'supply_chain.deliveries.create'))->get();
                $this->notifications->sendInApp($dispatch, 'return.case_updated', [
                    'title' => 'Check shipment arrival — '.$case->case_number,
                    'message' => 'The customer reports that the shipment has not arrived. Coordinate an update with Customer Service.',
                    'link_to' => '/supply-chain/deliveries/'.$case->delivery->hash_id,
                    'entity_type' => 'delivery', 'entity_id' => $case->delivery->hash_id,
                ], 'delivery-trace:'.$case->id);
            });
            return $this->show($case);
        }, 3);
    }

    public function canResolveTrace(ReturnCase $case, bool $customer = false): bool
    {
        if ($case->intake_kind?->value !== 'delivery_trace' || in_array($case->status->value, ['resolved', 'withdrawn', 'rejected'], true)) {
            return false;
        }
        $delivery = $case->delivery;
        if (! $delivery) {
            return false;
        }
        return in_array($delivery->status->value, ['delivered', 'confirmed'], true)
            || (! $customer && $delivery->status->value === 'returned' && $delivery->attemptOutcome?->reconciled_at);
    }

    public function act(ReturnCase $case, array $data, User $by, array $actor): ReturnCase
    {
        $result = DB::transaction(function () use ($case, $data, $by, $actor): ReturnCase {
            if ($case->intake_kind?->value === 'delivery_trace' && $case->delivery_id) {
                Delivery::query()->lockForUpdate()->findOrFail($case->delivery_id);
            }
            $case = ReturnCase::query()->lockForUpdate()->findOrFail($case->id);
            $case->load('lines');
            $action = $data['action'];
            $status = $case->status->value;
            $internal = $actor['type'] === 'internal';
            if (! $internal && ! in_array($action, ['reply', 'withdraw', 'reopen', 'acknowledge', 'resolve_trace'], true)) {
                abort(403);
            }
            if (! $internal && $actor['type'] === 'supplier' && $action === 'withdraw') {
                throw new BusinessRuleException('Purchasing must review a request to withdraw this receiving report. Reply with your explanation.');
            }
            if (in_array($status, ['resolved', 'withdrawn'], true)) {
                throw new BusinessRuleException('This case is closed. Submit a new report for another problem.');
            }
            if ($case->intake_kind?->value === 'delivery_trace'
                && ! in_array($action, ['reply', 'acknowledge', 'start_review', 'request_info', 'assign', 'reject', 'withdraw', 'reopen', 'resolve_trace'], true)) {
                throw new BusinessRuleException('A shipment tracking report cannot authorize goods, replacements or credit. First establish what arrived, then use a receipt problem report if needed.');
            }
            $message = trim((string) ($data['message'] ?? ''));
            $patch = [];
            switch ($action) {
                case 'resolve_trace':
                    if (! $this->canResolveTrace($case, ! $internal)) {
                        throw new BusinessRuleException('Record the shipment arrival or complete the depot reconciliation before closing this tracking report.');
                    }
                    if ($message === '') {
                        throw ValidationException::withMessages(['message' => 'Explain the confirmed arrival or the agreed next step for this shipment.']);
                    }
                    $patch = ['status' => 'resolved', 'resolved_at' => now()];
                    break;
                case 'reply':
                case 'acknowledge':
                    if ($message === '') {
                        throw ValidationException::withMessages(['message' => 'Add a message so the next person knows what changed.']);
                    }
                    if ($status === 'information_needed' && (! $internal || ($data['is_public'] ?? true))) {
                        $patch['status'] = $this->hasEffects($case) ? 'in_progress' : ($case->resolution ? 'action_agreed' : 'under_review');
                    }
                    break;
                case 'start_review':
                    $this->requireState($case, ['submitted', 'information_needed', 'under_review']);
                    $patch = ['status' => $this->hasEffects($case) ? 'in_progress' : ($case->resolution ? 'action_agreed' : 'under_review'), 'assigned_to' => $by->id];
                    $message = $message ?: 'Review started. We are checking the reported quantities.';
                    break;
                case 'request_info':
                    if ($message === '') {
                        throw ValidationException::withMessages(['message' => 'Explain exactly what information is needed.']);
                    }
                    $this->requireState($case, ['submitted', 'under_review', 'information_needed', 'action_agreed', 'in_progress']);
                    $patch['status'] = 'information_needed';
                    break;
                case 'assign':
                    $id = HashIdFilter::decode($data['assigned_to'] ?? '', User::class);
                    $owner = $id ? User::query()->where('is_active', true)->find($id) : null;
                    if (! $owner || ! $owner->hasPermission('return_management.manage')) {
                        throw ValidationException::withMessages(['assigned_to' => 'Choose an active return manager.']);
                    }
                    $patch['assigned_to'] = $owner->id;
                    $message = 'Case assigned to '.$owner->name.'.';
                    break;
                case 'agree':
                    $this->requireState($case, ['submitted', 'under_review', 'information_needed', 'action_agreed', 'in_progress']);
                    if ($this->hasEffects($case)) {
                        throw new BusinessRuleException('The agreed action has started. Resolve its linked documents before changing the agreement.');
                    }
                    $resolution = $data['resolution'] ?? null;
                    if (! in_array($resolution, ['return_goods', 'redelivery', 'credit', 'no_action'], true) || $message === '') {
                        throw ValidationException::withMessages(['resolution' => 'Choose an action and explain the agreement.']);
                    }
                    $verified = collect($data['lines'] ?? [])->keyBy('id');
                    if ($verified->keys()->diff($case->lines->pluck('hash_id'))->isNotEmpty()) {
                        throw ValidationException::withMessages(['lines' => 'Verified lines must belong to this case.']);
                    }
                    $totalVerified = '0';
                    foreach ($case->lines as $line) {
                        $row = $verified->get($line->hash_id);
                        $missing = (string) ($row['verified_missing_quantity'] ?? $line->missing_quantity);
                        $defective = (string) ($row['verified_defective_quantity'] ?? $line->defective_quantity);
                        if (bccomp($missing, '0', 3) < 0 || bccomp($defective, '0', 3) < 0 || bccomp($missing, $line->missing_quantity, 3) > 0 || bccomp($defective, $line->defective_quantity, 3) > 0) {
                            throw ValidationException::withMessages(['lines' => 'Verified quantities cannot exceed the reported quantities. Request a new report for additional goods.']);
                        }
                        if ($resolution === 'return_goods' && bccomp($missing, '0', 3) > 0) {
                            throw ValidationException::withMessages(['resolution' => 'Physical return alone cannot settle missing goods. Choose redelivery or credit for this case.']);
                        }
                        $replacementQuantity = bcadd($missing, $defective, 3);
                        $totalVerified = bcadd($totalVerified, $replacementQuantity, 3);
                        if ($resolution === 'redelivery' && $case->type->value === 'customer'
                            && bccomp($replacementQuantity, '0', 3) > 0 && $line->product_id === null) {
                            throw ValidationException::withMessages(['resolution' => 'A replacement needs a product linked to the source delivery. Review the source or choose another resolution.']);
                        }
                        if ($resolution === 'redelivery' && $case->type->value === 'customer'
                            && bccomp($replacementQuantity, bcadd($replacementQuantity, '0', 2), 3) !== 0) {
                            throw ValidationException::withMessages(['resolution' => 'Replacement orders support two decimal places. Choose another resolution for this fractional quantity; do not round the report.']);
                        }
                        $line->forceFill(['verified_missing_quantity' => $missing, 'verified_defective_quantity' => $defective])->save();
                    }
                    if ($resolution !== 'no_action' && bccomp($totalVerified, '0', 3) <= 0) {
                        throw ValidationException::withMessages(['resolution' => 'No affected quantity was verified. Use a reviewed no-action decision instead.']);
                    }
                    $this->releaseCancelledReturn($case, $actor);
                    $patch = ['status' => 'action_agreed', 'resolution' => $resolution, 'resolution_notes' => $message, 'expected_date' => $data['expected_date'] ?? null];
                    break;
                case 'create_replacement':
                    $this->requireState($case, ['action_agreed', 'in_progress']);
                    abort_unless($by->hasPermission('return_management.approve'), 403);
                    if ($case->type->value !== 'customer' || $case->resolution?->value !== 'redelivery' || $case->credit_note_id) {
                        throw new BusinessRuleException('Agree customer redelivery without a separate credit before authorizing replacement.');
                    }
                    if ($case->return_request_id && $case->returnRequest?->credit_note_id) {
                        throw new BusinessRuleException('The linked return already has a credit. Review Finance settlement before authorizing replacement.');
                    }
                    $order = app(\App\Modules\CRM\Services\SalesOrderService::class)->createNoChargeReplacement($case, (int) $by->id);
                    $patch = ['replacement_sales_order_id' => $order->id, 'status' => 'in_progress'];
                    $message = 'No-charge replacement authorized. The replacement order follows normal confirmation, Quality and delivery controls.';
                    break;
                case 'create_return':
                    $this->requireState($case, ['action_agreed', 'in_progress']);
                    $this->settlements->createReturn($case, $by);
                    $patch['status'] = 'in_progress';
                    $message = 'Physical return request prepared. Approval and Quality checks follow the normal return workflow.';
                    break;
                case 'create_credit':
                    $this->requireState($case, ['action_agreed', 'in_progress']);
                    abort_unless($by->hasPermission('accounting.credit_notes.manage'), 403);
                    $this->settlements->createCredit($case, $by);
                    $patch['status'] = 'in_progress';
                    $message = 'Credit prepared for Finance. It has not been issued or paid yet.';
                    break;
                case 'link_resolution':
                    $this->requireState($case, ['action_agreed', 'in_progress']);
                    $this->settlements->link($case, $data, $by);
                    $patch['status'] = 'in_progress';
                    $message = $message ?: 'Resolution document linked. Completion will be checked before this case closes.';
                    break;
                case 'resolve':
                    $this->requireState($case, ['action_agreed', 'in_progress']);
                    $this->settlements->assertComplete($case, $by);
                    $patch = ['status' => 'resolved', 'resolved_at' => now()];
                    $message = $message ?: 'The agreed action is complete. This case is resolved.';
                    break;
                case 'reject':
                case 'withdraw':
                    if ($this->hasEffects($case)) {
                        throw new BusinessRuleException('Linked returns or settlement documents require review before this case can be closed.');
                    }
                    if ($message === '') {
                        throw ValidationException::withMessages(['message' => 'Explain why this report is being closed.']);
                    }
                    $this->releaseCancelledReturn($case, $actor);
                    $patch['status'] = $action === 'reject' ? 'rejected' : 'withdrawn';
                    break;
                case 'reopen':
                    $this->requireState($case, ['rejected']);
                    if ($message === '') {
                        throw ValidationException::withMessages(['message' => 'Explain why you want this decision reviewed.']);
                    }
                    $patch = ['status' => 'under_review', 'resolution' => null];
                    break;
                default:
                    throw ValidationException::withMessages(['action' => 'Choose an available case action.']);
            }
            $case->forceFill($patch)->save();
            $this->event($case, $action, $message, $actor, $action === 'reply' && $internal ? (bool) ($data['is_public'] ?? true) : true);
            DB::afterCommit(fn () => $this->notify($case, 'Problem report updated', ! ($action === 'reply' && $internal && ! ($data['is_public'] ?? true))));

            return $case;
        });

        return $this->show($result);
    }

    public function attach(ReturnCase $case, UploadedFile $file, array $actor): ReturnCaseAttachment
    {
        $path = $file->store('return-case-evidence', 'local');
        try {
            return DB::transaction(function () use ($case, $file, $actor, $path) {
                $case = ReturnCase::query()->lockForUpdate()->findOrFail($case->id);
                if (in_array($case->status->value, ['resolved', 'withdrawn'], true)) {
                    throw new BusinessRuleException('This case is closed and cannot accept new evidence.');
                }
                $event = $this->event($case, 'evidence_added', 'Evidence attached: '.$file->getClientOriginalName(), $actor);
                $attachment = $case->attachments()->create([
                    'event_id' => $event->id, 'file_name' => $file->getClientOriginalName(), 'path' => $path,
                    'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                    'created_by' => $actor['type'] === 'internal' ? $actor['id'] : null,
                    'customer_portal_user_id' => $actor['type'] === 'customer' ? $actor['id'] : null,
                    'supplier_portal_user_id' => $actor['type'] === 'supplier' ? $actor['id'] : null,
                ]);
                if ($case->status->value === 'information_needed' && $actor['type'] !== 'internal') {
                    $case->forceFill(['status' => $this->hasEffects($case) ? 'in_progress' : ($case->resolution ? 'action_agreed' : 'under_review')])->save();
                }
                DB::afterCommit(fn () => $this->notify($case, 'New evidence received'));
                return $attachment;
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function assertBillingClear(int $deliveryId): void
    {
        $held = Delivery::withTrashed()->find($deliveryId)?->blockingReturnCase()->first();
        if ($held) {
            throw new BusinessRuleException("Problem report {$held->case_number} is awaiting resolution. Review it before confirming or billing this delivery.");
        }
    }

    private function requireState(ReturnCase $case, array $states): void
    {
        if (! in_array($case->status->value, $states, true)) {
            throw new BusinessRuleException('This action is unavailable while the case is '.$case->status->label().'.');
        }
    }

    private function hasEffects(ReturnCase $case): bool
    {
        return ($case->return_request_id !== null && ! $this->isUnhandledClosedReturn($case)) || $case->credit_note_id !== null
            || $case->replacement_delivery_id !== null || $case->resolution_goods_receipt_note_id !== null
            || $case->replacement_sales_order_id !== null || $case->replacement_purchase_order_id !== null;
    }

    public function canReviseAgreement(ReturnCase $case): bool
    {
        return $case->intake_kind?->value !== 'delivery_trace'
            && in_array($case->status->value, ['submitted', 'under_review', 'information_needed', 'action_agreed', 'in_progress'], true)
            && ! $this->hasEffects($case);
    }

    private function isUnhandledClosedReturn(ReturnCase $case): bool
    {
        $rma = $case->returnRequest;

        return $rma !== null && in_array($rma->status->value, ['cancelled', 'rejected'], true)
            && $rma->received_at === null && $rma->credit_note_id === null
            && $rma->replacement_purchase_order_id === null && $rma->stock_movement_id === null;
    }

    private function releaseCancelledReturn(ReturnCase $case, array $actor): void
    {
        if ($case->return_request_id !== null && $this->isUnhandledClosedReturn($case)) {
            $rma = $case->returnRequest;
            $this->event($case, 'release_cancelled_return', 'Previous return '.$rma->rma_number.' was '.$rma->status->value.' before physical or financial handling. Its case agreement can be reviewed again.', $actor);
            $case->forceFill(['return_request_id' => null])->save();
            $case->unsetRelation('returnRequest');
        }
    }

    private function internalActor(User $by): array
    {
        return ['type' => 'internal', 'id' => $by->id, 'name' => $by->name];
    }

    private function event(ReturnCase $case, string $action, string $message, array $actor, bool $public = true): \App\Modules\ReturnManagement\Models\ReturnCaseEvent
    {
        return $case->events()->create([
            'action' => $action, 'message' => $message, 'actor_type' => $actor['type'], 'actor_name' => $actor['name'],
            'user_id' => $actor['type'] === 'internal' ? $actor['id'] : null,
            'customer_portal_user_id' => $actor['type'] === 'customer' ? $actor['id'] : null,
            'supplier_portal_user_id' => $actor['type'] === 'supplier' ? $actor['id'] : null,
            'is_public' => $public,
        ]);
    }

    private function notify(ReturnCase $case, string $title, bool $public = true): void
    {
        try {
            $recipients = $case->assigned_to ? User::query()->where('is_active', true)->whereKey($case->assigned_to)->get()
                : User::query()->where('is_active', true)->whereHas('role.permissions', fn ($q) => $q->where('slug', 'return_management.manage'))->get();
            if ($recipients->isNotEmpty()) {
                $this->notifications->send($recipients, 'return.case_updated', [
                    'title' => $title.' — '.$case->case_number, 'message' => 'Open the case to review the latest quantities and next action.',
                    'link_to' => '/return-management/cases/'.$case->hash_id, 'entity_type' => 'return_case', 'entity_id' => $case->hash_id,
                ]);
            }
            if ($public) {
                $realm = $case->type->value;
                $portalClass = $realm === 'customer' ? \App\Modules\B2B\Models\CustomerPortalUser::class : \App\Modules\B2B\Models\SupplierPortalUser::class;
                $contacts = $portalClass::query()->where($realm === 'customer' ? 'customer_id' : 'vendor_id', $realm === 'customer' ? $case->customer_id : $case->vendor_id)
                    ->where('is_active', true)->get();
                foreach ($contacts as $contact) {
                    \Illuminate\Support\Facades\Mail::to($contact->email)->queue(new \App\Modules\ReturnManagement\Mail\ReturnCaseUpdateMail(
                        $case->case_number, $case->status->label(), $realm, $case->hash_id, $recipients->pluck('id')->all(),
                    ));
                }
                if ($contacts->isEmpty() && $recipients->isNotEmpty()) {
                    $this->notifications->sendInApp($recipients, 'return.case_updated', [
                        'title' => $case->case_number.' needs direct follow-up',
                        'message' => 'There is no active portal contact. Contact the customer or supplier about this report.',
                        'link_to' => '/return-management/cases/'.$case->hash_id,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Return case notification failed', ['case_id' => $case->id, 'error' => $e->getMessage()]);
        }
    }
}
