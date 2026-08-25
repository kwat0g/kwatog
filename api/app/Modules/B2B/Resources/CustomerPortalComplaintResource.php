<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\CRM\Enums\ComplaintStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Customer-safe complaint representation.
 *
 * The CRM resource intentionally includes internal NCR handoff, assignment,
 * creator, and 8D fields. A portal response is an explicit allowlist instead
 * of relying on relation loading to decide what a customer can see.
 */
class CustomerPortalComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof ComplaintStatus
            ? $this->status
            : ComplaintStatus::tryFrom((string) $this->status);
        $statusValue = $status?->value ?? (string) $this->status;
        $severityValue = $this->severity instanceof \BackedEnum
            ? $this->severity->value
            : (string) $this->severity;

        return [
            'id'                => $this->hash_id,
            'complaint_number'  => $this->complaint_number,
            'severity'         => $severityValue,
            'severity_label'    => Str::headline($severityValue),
            'status'            => $statusValue,
            'status_label'      => Str::headline($statusValue),
            'description'       => $this->description,
            'affected_quantity' => (int) $this->affected_quantity,
            'received_date'     => optional($this->received_date)?->toDateString(),
            'resolved_at'       => optional($this->resolved_at)?->toISOString(),
            'closed_at'         => optional($this->closed_at)?->toISOString(),
            'created_at'        => optional($this->created_at)?->toISOString(),
        ];
    }
}
