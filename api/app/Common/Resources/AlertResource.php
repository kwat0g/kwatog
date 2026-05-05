<?php

declare(strict_types=1);

namespace App\Common\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'type'         => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'severity'     => $this->severity instanceof \BackedEnum ? $this->severity->value : $this->severity,
            'title'        => $this->title,
            'message'      => $this->message,
            'entity_type'  => $this->entity_type ? class_basename($this->entity_type) : null,
            'entity_id'    => $this->entity_id, // raw — entity is internal; consumer can use the hash via entity preview
            'entity'       => $this->whenLoaded('entity', function () {
                $e = $this->entity;
                if (! $e) return null;
                return [
                    'id'    => method_exists($e, 'getHashIdAttribute') ? $e->hash_id : $e->getKey(),
                    'label' => $e->name ?? $e->code ?? $e->wo_number ?? $e->invoice_number ?? $e->bill_number ?? $e->mold_code ?? $e->machine_code ?? $e->part_number ?? (string) $e->getKey(),
                    'type'  => class_basename($e::class),
                ];
            }),
            'metadata'     => $this->metadata ?? [],
            'is_read'      => (bool) $this->is_read,
            'is_dismissed' => (bool) $this->is_dismissed,
            'dismissed_at' => optional($this->dismissed_at)->toIso8601String(),
            'created_at'   => optional($this->created_at)->toIso8601String(),
        ];
    }
}
