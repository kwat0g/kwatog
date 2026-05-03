<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\HR\Models\Clearance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Clearance
 */
class ClearanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cleared = collect($this->clearance_items ?? [])
            ->filter(fn ($i) => ($i['status'] ?? '') === 'cleared')->count();
        $total   = count($this->clearance_items ?? []);

        return [
            'id'                  => $this->hash_id,
            'clearance_no'        => $this->clearance_no,
            'employee'            => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'           => $this->employee->hash_id,
                'employee_no'  => $this->employee->employee_no,
                'full_name'    => trim(($this->employee->first_name ?? '').' '.($this->employee->last_name ?? '')),
                'department'   => $this->employee->department ? [
                    'id'   => $this->employee->department->hash_id,
                    'name' => $this->employee->department->name,
                    'code' => $this->employee->department->code,
                ] : null,
                'position'     => $this->employee->position ? [
                    'id'    => $this->employee->position->hash_id,
                    'title' => $this->employee->position->title,
                ] : null,
                'pay_type'     => $this->employee->pay_type,
                'date_hired'   => optional($this->employee->date_hired)?->toDateString(),
            ] : null),
            'separation_date'     => optional($this->separation_date)?->toDateString(),
            'separation_reason'   => $this->separation_reason instanceof \BackedEnum ? $this->separation_reason->value : $this->separation_reason,
            'clearance_items'     => $this->clearance_items ?? [],
            'cleared_count'       => $cleared,
            'items_total'         => $total,
            'progress_pct'        => $total > 0 ? (int) round(($cleared / $total) * 100) : 0,
            'final_pay_computed'  => (bool) $this->final_pay_computed,
            'final_pay_amount'    => $this->final_pay_amount !== null ? (string) $this->final_pay_amount : null,
            'final_pay_breakdown' => $this->final_pay_breakdown,
            'journal_entry'       => $this->journal_entry_id ? (function () {
                $je = JournalEntry::find($this->journal_entry_id);
                return $je ? ['id' => $je->hash_id, 'entry_number' => $je->entry_number] : null;
            })() : null,
            'status'              => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'initiator'           => $this->whenLoaded('initiator', fn () => $this->initiator ? [
                'id' => $this->initiator->hash_id, 'name' => $this->initiator->name,
            ] : null),
            'finalizer'           => $this->whenLoaded('finalizer', fn () => $this->finalizer ? [
                'id' => $this->finalizer->hash_id, 'name' => $this->finalizer->name,
            ] : null),
            'finalized_at'        => optional($this->finalized_at)?->toISOString(),
            'remarks'             => $this->remarks,
            'created_at'          => optional($this->created_at)?->toISOString(),
            'updated_at'          => optional($this->updated_at)?->toISOString(),
        ];
    }
}
