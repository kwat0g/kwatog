<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Support\SearchOperator;
use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Services\NcrService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sprint 7 — Task 68. Customer complaints lifecycle.
 *
 *   create()         — opens the complaint and auto-creates an NCR
 *   update8DReport() — upserts the 8D report fields
 *   finalize8D()     — locks the 8D report, stamps finalized_by/_at
 *   resolve()        — flags status=resolved (NCR closure handled separately)
 *   close()          — flags status=closed; if NCR still open, do nothing
 */
class ComplaintService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = CustomerComplaint::query()->with([
            'customer:id,name',
            'product:id,part_number,name',
            'salesOrder:id,so_number',
            'ncr:id,ncr_number,status',
            'creator:id,name',
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
            'creator:id,name',
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
        return DB::transaction(function () use ($data, $by) {
            $complaint = CustomerComplaint::create([
                'complaint_number'  => $this->sequences->generate('complaint'),
                'customer_id'       => (int) $data['customer_id'],
                'product_id'        => $data['product_id']      ?? null,
                'sales_order_id'    => $data['sales_order_id']  ?? null,
                'received_date'     => $data['received_date'],
                'severity'          => $data['severity'],
                'status'            => 'open',
                'description'       => $data['description'],
                'affected_quantity' => (int) ($data['affected_quantity'] ?? 0),
                'created_by'        => $by->id,
                'assigned_to'       => $data['assigned_to'] ?? null,
            ]);

            // Seed an empty 8D report so the editor has something to upsert.
            Complaint8DReport::create([
                'complaint_id' => $complaint->id,
                'd2_problem'   => $data['description'], // pre-fill from initial complaint
            ]);

            // Auto-open NCR (Task 61).
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
                $complaint->forceFill(['ncr_id' => $ncr->id])->save();
            } catch (\Throwable) {
                // NCR auto-open is best-effort; user can still operate the complaint manually.
            }

            return $this->show($complaint);
        });
    }

    public function update8DReport(CustomerComplaint $c, array $data): Complaint8DReport
    {
        $report = $c->eightDReport ?? Complaint8DReport::firstOrCreate(['complaint_id' => $c->id]);
        if ($report->finalized_at) {
            throw new RuntimeException('8D report is finalised and cannot be edited.');
        }
        $allowed = ['d1_team','d2_problem','d3_containment','d4_root_cause','d5_corrective_action','d6_verification','d7_prevention','d8_recognition'];
        $patch = array_intersect_key($data, array_flip($allowed));
        if (! empty($patch)) {
            $report->update($patch);
        }
        return $report->fresh();
    }

    public function finalize8D(CustomerComplaint $c, User $by): Complaint8DReport
    {
        $report = $c->eightDReport ?? throw new RuntimeException('No 8D report exists for this complaint.');
        if ($report->finalized_at) return $report;
        $report->update([
            'finalized_at' => now(),
            'finalized_by' => $by->id,
        ]);
        return $report->fresh();
    }

    public function resolve(CustomerComplaint $c): CustomerComplaint
    {
        $current = $c->status instanceof ComplaintStatus ? $c->status : ComplaintStatus::from((string) $c->status);
        if ($current->isTerminal()) {
            throw new RuntimeException('Complaint is already terminal.');
        }
        $c->forceFill([
            'status'      => ComplaintStatus::Resolved->value,
            'resolved_at' => now(),
        ])->save();
        return $this->show($c);
    }

    public function close(CustomerComplaint $c): CustomerComplaint
    {
        $current = $c->status instanceof ComplaintStatus ? $c->status : ComplaintStatus::from((string) $c->status);
        if ($current->isTerminal()) return $this->show($c);
        $c->forceFill([
            'status'    => ComplaintStatus::Closed->value,
            'closed_at' => now(),
        ])->save();
        return $this->show($c);
    }
}
