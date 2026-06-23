<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpportunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'opportunity_number'  => $this->opportunity_number,
            'title'               => $this->title,
            'stage'               => $this->stage?->value,
            'stage_label'         => $this->stage?->label(),
            'probability'         => (int) $this->probability,
            'estimated_value'     => (string) $this->estimated_value,
            'expected_close_date' => optional($this->expected_close_date)->toDateString(),
            'actual_close_date'   => optional($this->actual_close_date)->toDateString(),
            'lost_reason'         => $this->lost_reason,
            'notes'               => $this->notes,
            'is_terminal'         => $this->stage?->isTerminal() ?? false,
            'customer'  => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'assignee'  => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id'   => $this->assignee->hash_id,
                'name' => $this->assignee->name,
            ] : null),
            'lead'      => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id'           => $this->lead->hash_id,
                'lead_number'  => $this->lead->lead_number,
                'company_name' => $this->lead->company_name,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
