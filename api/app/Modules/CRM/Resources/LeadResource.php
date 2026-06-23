<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->hash_id,
            'lead_number'                 => $this->lead_number,
            'company_name'                => $this->company_name,
            'contact_person'              => $this->contact_person,
            'email'                       => $this->email,
            'phone'                       => $this->phone,
            'source'                      => $this->source?->value,
            'source_label'                => $this->source?->label(),
            'status'                      => $this->status?->value,
            'status_label'                => $this->status?->label(),
            'estimated_value'             => $this->estimated_value !== null ? (string) $this->estimated_value : null,
            'notes'                       => $this->notes,
            'converted_to_opportunity_id' => $this->converted_to_opportunity_id
                ? app('hashids')->encode($this->converted_to_opportunity_id)
                : null,
            'assignee'  => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id'   => $this->assignee->hash_id,
                'name' => $this->assignee->name,
            ] : null),
            'customer'  => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
