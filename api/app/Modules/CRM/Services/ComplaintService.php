<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\SearchOperator;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintNcrHandoffStatus;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Events\ComplaintNcrRequested;
use App\Modules\CRM\Events\CustomerComplaintUpdated;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7 — Task 68. Customer complaints lifecycle.
 *
 *   create()         — opens the complaint and auto-creates an NCR
 *   update8DReport() — upserts the 8D report fields
 *   finalize8D()     — locks the 8D report, stamps finalized_by/_at
 *   resolve()        — flags status=resolved after quality completion
 *   close()          — flags status=closed after quality completion
 */
class ComplaintService
{
    /**
     * Complaint lifecycle operations currently exposed by the CRM routes.
     *
     * The complaint row is the authoritative state machine. The matrix is
     * deliberately local to this service until a separate cancellation/start-
     * investigation workflow is introduced.
     *
     * @var array<string, list<string>>
     */
    private const LIFECYCLE_TRANSITIONS = [
        'resolve' => [
            ComplaintStatus::Open->value,
            ComplaintStatus::Investigating->value,
        ],
        'close' => [
            ComplaintStatus::Resolved->value,
        ],
    ];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SettingsService $settings,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = CustomerComplaint::query()->with([
            'customer:id,name',
            'product:id,part_number,name',
            'salesOrder:id,so_number',
            'ncr:id,ncr_number,status',
            'creator:id,name,role_id',
            'assignee:id,name',
        ]);

        foreach (['status', 'severity'] as $f) if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        if (! empty($filters['customer_id'])) $q->where('customer_id', (int) $filters['customer_id']);
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('complaint_number', SearchOperator::like(), $term)
                ->orWhere('description', SearchOperator::like(), $term));
        }
        return $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(CustomerComplaint $c): CustomerComplaint
    {
        return $c->load([
            'customer:id,name,email,contact_person',
            'product:id,part_number,name',
            'salesOrder:id,so_number',
            'ncr:id,ncr_number,status,severity,disposition',
            'replacementWorkOrder:id,wo_number,status,quantity_target',
            'creator:id,name,role_id',
            'assignee:id,name',
            'eightDReport',
        ]);
    }

    /**
     * @param array{
     *   customer_id: int, product_id?: int|null, sales_order_id?: int|null,
     *   received_date: string, severity: string, description: string,
     *   affected_quantity?: int, assigned_to?: int|null
     * } $data
     */
    public function create(array $data, User $by): CustomerComplaint
    {
        $complaint = DB::transaction(function () use ($data, $by) {
            $this->assertSourceRecords($data);

            $complaint = CustomerComplaint::create([
                'complaint_number'  => $this->sequences->generate('complaint'),
                'customer_id'       => (int) $data['customer_id'],
                'product_id'        => $data['product_id']      ?? null,
                'sales_order_id'    => $data['sales_order_id']  ?? null,
                'received_date'     => $data['received_date'],
                'severity'          => $data['severity'],
                'status'            => 'open',
                'ncr_handoff_status' => ComplaintNcrHandoffStatus::NotStarted,
                'description'       => $data['description'],
                'affected_quantity' => (int) ($data['affected_quantity'] ?? 0),
                'created_by'        => $by->id,
                'assigned_to'       => $data['assigned_to'] ?? null,
                'd3_due_at'         => now()->addHours($this->settings->requiredInt('crm.complaint_8d.d3_due_hours', 1)),
                'd4_due_at'         => now()->addDays($this->settings->requiredInt('crm.complaint_8d.d4_due_days', 1)),
                'finalize_due_at'   => now()->addDays($this->settings->requiredInt('crm.complaint_8d.finalize_due_days', 1)),
                'sla_alert_levels'  => [],
            ]);

            // Seed an empty 8D report so the editor has something to upsert.
            Complaint8DReport::create([
                'complaint_id' => $complaint->id,
                'd2_problem'   => $data['description'], // pre-fill from initial complaint
            ]);

            // Auto-open NCR (Task 61). Expected Quality setup failures leave
            // the complaint committed, but create a durable recovery request;
            // unexpected infrastructure failures still roll back the complaint.
            try {
                $ncr = app(NcrService::class)->create([
                    'source'             => NcrSource::CustomerComplaint->value,
                    'severity'           => NcrSeverity::from((string) $data['severity'])->value,
                    'product_id'         => $complaint->product_id,
                    'complaint_id'       => $complaint->id,
                    'defect_description' => 'Customer complaint '.$complaint->complaint_number.': '.$complaint->description,
                    'affected_quantity'  => $complaint->affected_quantity,
                    'assigned_to'        => $complaint->assigned_to,
                ], $by);
                $complaint->forceFill([
                    'ncr_id' => $ncr->id,
                    'ncr_handoff_status' => ComplaintNcrHandoffStatus::Generated,
                    'ncr_handoff_message' => null,
                    'ncr_handoff_at' => now(),
                ])->save();
            } catch (BusinessRuleException|ModelNotFoundException $e) {
                $complaint->forceFill([
                    'ncr_handoff_status' => ComplaintNcrHandoffStatus::ManualRequired,
                    'ncr_handoff_message' => 'NCR creation requires manual action: ' . $e->getMessage(),
                    'ncr_handoff_at' => now(),
                ])->save();
                $this->recordComplaintNcrRequest($complaint);
            }

            return $this->show($complaint);
        });

        event(new CustomerComplaintUpdated($complaint, 'created'));

        return $complaint;
    }

    public function update8DReport(CustomerComplaint $c, array $data): Complaint8DReport
    {
        return DB::transaction(function () use ($c, $data): Complaint8DReport {
            // Lock the parent first so update and finalize use one order and a
            // stale route-bound report cannot race a finalized write.
            $lockedComplaint = CustomerComplaint::query()
                ->lockForUpdate()
                ->findOrFail($c->id);
            $report = Complaint8DReport::query()
                ->where('complaint_id', $lockedComplaint->id)
                ->lockForUpdate()
                ->first();

            if (! $report) {
                $report = Complaint8DReport::create(['complaint_id' => $lockedComplaint->id]);
            }
            if ($report->finalized_at) {
                throw new BusinessRuleException('8D report is finalised and cannot be edited.');
            }

            $allowed = [
                'd1_team', 'd2_problem', 'd3_containment', 'd4_root_cause',
                'd5_corrective_action', 'd6_verification', 'd7_prevention',
                'd8_recognition',
            ];
            $patch = array_intersect_key($data, array_flip($allowed));
            if (! empty($patch)) {
                $report->update($patch);
            }

            return $report->fresh();
        });
    }

    public function finalize8D(CustomerComplaint $c, User $by): Complaint8DReport
    {
        [$report, $finalizedNow] = DB::transaction(function () use ($c, $by): array {
            // Match update8DReport's complaint → report lock order. The
            // finalization guard must run against the locked authoritative row.
            $lockedComplaint = CustomerComplaint::query()
                ->lockForUpdate()
                ->findOrFail($c->id);
            $report = Complaint8DReport::query()
                ->where('complaint_id', $lockedComplaint->id)
                ->lockForUpdate()
                ->first();

            if (! $report) {
                throw new BusinessRuleException('No 8D report exists for this complaint.');
            }
            if ($report->finalized_at) {
                return [$report->fresh(['complaint']), false];
            }

            // T3.2.A — every D must be populated (non-empty after trim).
            $required = [
                'd1_team', 'd2_problem', 'd3_containment', 'd4_root_cause',
                'd5_corrective_action', 'd6_verification', 'd7_prevention', 'd8_recognition',
            ];
            foreach ($required as $field) {
                if (trim((string) $report->{$field}) === '') {
                    throw new BusinessRuleException("Cannot finalize 8D: {$field} is required.");
                }
            }

            $report->update([
                'finalized_at' => now(),
                'finalized_by' => $by->id,
            ]);

            return [$report->fresh(['complaint']), true];
        });

        // Publish only after the transaction commits. A rollback must not tell
        // listeners that a formal 8D report exists.
        if ($finalizedNow && $report->complaint) {
            event(new CustomerComplaintUpdated($report->complaint, '8D report finalized'));
        }

        return $report;
    }

    public function resolve(CustomerComplaint $c): CustomerComplaint
    {
        return $this->transitionLifecycle($c, ComplaintStatus::Resolved, 'resolve');
    }

    public function close(CustomerComplaint $c): CustomerComplaint
    {
        return $this->transitionLifecycle($c, ComplaintStatus::Closed, 'close');
    }

    /** Retry a previously failed complaint → Quality NCR handoff. */
    public function retryNcrHandoff(CustomerComplaint $complaint, User $by): CustomerComplaint
    {
        return DB::transaction(function () use ($complaint, $by): CustomerComplaint {
            $complaint = CustomerComplaint::query()->lockForUpdate()->findOrFail($complaint->id);
            if ($complaint->ncr_id !== null) {
                return $this->show($complaint);
            }

            $ncr = NonConformanceReport::query()
                ->where('complaint_id', $complaint->id)
                ->first();

            if (! $ncr) {
                try {
                    $ncr = app(NcrService::class)->create([
                        'source' => NcrSource::CustomerComplaint->value,
                        'severity' => ($complaint->severity instanceof NcrSeverity
                            ? $complaint->severity
                            : NcrSeverity::from((string) $complaint->severity))->value,
                        'product_id' => $complaint->product_id,
                        'complaint_id' => $complaint->id,
                        'defect_description' => 'Customer complaint ' . $complaint->complaint_number . ': ' . $complaint->description,
                        'affected_quantity' => $complaint->affected_quantity,
                        'assigned_to' => $complaint->assigned_to,
                    ], $by);
                } catch (BusinessRuleException|ModelNotFoundException $e) {
                    $complaint->forceFill([
                        'ncr_handoff_status' => ComplaintNcrHandoffStatus::ManualRequired,
                        'ncr_handoff_message' => 'NCR creation requires manual action: ' . $e->getMessage(),
                        'ncr_handoff_at' => now(),
                    ])->save();
                    $this->recordComplaintNcrRequest($complaint);

                    return $this->show($complaint);
                }
            }

            $complaint->forceFill([
                'ncr_id' => $ncr->id,
                'ncr_handoff_status' => ComplaintNcrHandoffStatus::Generated,
                'ncr_handoff_message' => null,
                'ncr_handoff_at' => now(),
            ])->save();

            return $this->show($complaint);
        });
    }

    public function markNcrHandoffManual(int $complaintId, ?string $message = null): void
    {
        CustomerComplaint::query()->whereKey($complaintId)->update([
            'ncr_handoff_status' => ComplaintNcrHandoffStatus::ManualRequired,
            'ncr_handoff_message' => $message ?: 'NCR creation requires manual action.',
            'ncr_handoff_at' => now(),
        ]);
    }

    private function ensureNcrHandoffReady(CustomerComplaint $complaint): void
    {
        if ($complaint->ncr_id === null
            || $complaint->ncr_handoff_status !== ComplaintNcrHandoffStatus::Generated) {
            throw new BusinessRuleException(
                'The complaint cannot be resolved or closed until its Quality NCR handoff succeeds.'
            );
        }
    }

    /**
     * A complaint cannot advertise commercial resolution until the linked
     * quality investigation is complete. The caller already owns the
     * complaint row lock; report and NCR locks follow the same order used by
     * update8DReport()/finalize8D() and the Quality service's NCR writes.
     */
    private function ensureQualityCompletionReady(CustomerComplaint $complaint): void
    {
        $this->ensureNcrHandoffReady($complaint);

        $report = Complaint8DReport::query()
            ->where('complaint_id', $complaint->id)
            ->lockForUpdate()
            ->first();
        if (! $report || ! $report->finalized_at) {
            throw new BusinessRuleException(
                'The complaint cannot be resolved or closed until its 8D report is finalised.',
            );
        }

        $ncr = NonConformanceReport::query()
            ->lockForUpdate()
            ->find($complaint->ncr_id);
        if (! $ncr
            || (int) $ncr->complaint_id !== (int) $complaint->id
            || $ncr->status !== NcrStatus::Closed
            || $ncr->disposition === null) {
            throw new BusinessRuleException(
                'The complaint cannot be resolved or closed until its linked NCR is closed with a disposition.',
            );
        }
    }

    private function transitionLifecycle(
        CustomerComplaint $complaint,
        ComplaintStatus $target,
        string $operation,
    ): CustomerComplaint {
        [$updated, $changed] = DB::transaction(function () use ($complaint, $target, $operation): array {
            $locked = CustomerComplaint::query()
                ->lockForUpdate()
                ->findOrFail($complaint->id);
            $current = $locked->status instanceof ComplaintStatus
                ? $locked->status
                : ComplaintStatus::from((string) $locked->status);

            // Replaying the same operation is safe and does not rewrite its
            // audit timestamp or emit a duplicate lifecycle event.
            if ($current === $target) {
                return [$this->show($locked), false];
            }

            $allowed = self::LIFECYCLE_TRANSITIONS[$operation] ?? [];
            if (! in_array($current->value, $allowed, true)) {
                throw new BusinessRuleException(sprintf(
                    'Complaint cannot %s from status %s.',
                    $operation,
                    $current->value,
                ));
            }

            $this->ensureQualityCompletionReady($locked);
            $locked->forceFill(match ($target) {
                ComplaintStatus::Resolved => [
                    'status' => $target->value,
                    'resolved_at' => now(),
                ],
                ComplaintStatus::Closed => [
                    'status' => $target->value,
                    'closed_at' => now(),
                ],
                default => ['status' => $target->value],
            })->save();

            return [$this->show($locked), true];
        });

        if ($changed) {
            event(new CustomerComplaintUpdated($updated, $target->value));
        }

        return $updated;
    }

    /**
     * Re-check all complaint provenance and assignment references while the
     * write transaction owns the authoritative rows. Request validation alone
     * cannot prevent a source record being archived or reassigned mid-request.
     *
     * @param array<string, mixed> $data
     */
    private function assertSourceRecords(array $data): void
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $productId = isset($data['product_id']) && $data['product_id'] !== null
            ? (int) $data['product_id']
            : null;
        $salesOrderId = isset($data['sales_order_id']) && $data['sales_order_id'] !== null
            ? (int) $data['sales_order_id']
            : null;

        // Match SalesOrderService's order → product → customer lock order for
        // complaints that cite a sales order, reducing cross-module deadlocks.
        $salesOrder = null;
        if ($salesOrderId !== null) {
            $salesOrder = SalesOrder::query()->lockForUpdate()->find($salesOrderId);
            if (! $salesOrder || $salesOrder->status === SalesOrderStatus::Cancelled) {
                throw new BusinessRuleException('The selected sales order is inactive or no longer exists.');
            }
        }

        if ($productId !== null) {
            $product = Product::query()->lockForUpdate()->find($productId);
            if (! $product || ! $product->is_active) {
                throw new BusinessRuleException('The selected product is inactive or no longer exists.');
            }
        }

        $customer = Customer::query()->lockForUpdate()->find($customerId);
        if (! $customer || ! $customer->is_active) {
            throw new BusinessRuleException('The selected customer is inactive or no longer exists.');
        }

        if ($salesOrder) {
            if ((int) $salesOrder->customer_id !== (int) $customer->id) {
                throw new BusinessRuleException('The selected sales order does not belong to the selected customer.');
            }
            if ($productId !== null && ! $salesOrder->items()->where('product_id', $productId)->exists()) {
                throw new BusinessRuleException('The selected product is not part of the selected sales order.');
            }
        }

        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            $assignee = User::query()->lockForUpdate()->find((int) $data['assigned_to']);
            if (! $assignee || ! $assignee->is_active || ! $assignee->hasPermission('crm.complaints.manage')) {
                throw new BusinessRuleException('The selected assignee is inactive or not authorized to manage complaints.');
            }
        }
    }

    private function recordComplaintNcrRequest(CustomerComplaint $complaint): void
    {
        $dedupeKey = 'complaint-ncr-request:' . $complaint->id;
        if (DB::table('event_outbox')->where('dedupe_key', $dedupeKey)->exists()) {
            return;
        }

        app(OutboxService::class)->recordForChain(
            new ComplaintNcrRequested($complaint),
            $complaint,
            'crm_quality',
            'customer_complaint',
            'ncr_handoff',
            $dedupeKey,
        );
    }
}
