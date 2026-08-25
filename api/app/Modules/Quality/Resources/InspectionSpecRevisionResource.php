<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use App\Modules\Quality\Enums\InspectionParameterType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Read-only, reconstructable inspection-spec revision payload. */
class InspectionSpecRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'version' => (int) $this->version,
            'is_current' => $this->whenLoaded('spec', fn (): bool =>
                (int) $this->spec->version === (int) $this->version
            ),
            'notes' => $this->notes,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->hash_id,
                'parameter_name' => $item->parameter_name,
                'parameter_type' => (string) ($item->parameter_type?->value ?? $item->parameter_type),
                'parameter_type_label' => InspectionParameterType::tryFrom(
                    (string) ($item->parameter_type?->value ?? $item->parameter_type)
                )?->label(),
                'unit_of_measure' => $item->unit_of_measure,
                'nominal_value' => $item->nominal_value !== null ? (string) $item->nominal_value : null,
                'tolerance_min' => $item->tolerance_min !== null ? (string) $item->tolerance_min : null,
                'tolerance_max' => $item->tolerance_max !== null ? (string) $item->tolerance_max : null,
                'is_critical' => (bool) $item->is_critical,
                'sort_order' => (int) $item->sort_order,
                'notes' => $item->notes,
                'deleted_at' => optional($item->deleted_at)?->toIso8601String(),
            ])->values()),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
