<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class NcrTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'name'               => $this->name,
            'source'             => $this->source,
            'source_label'       => Str::headline((string) $this->source),
            'severity'           => $this->severity,
            'severity_label'     => Str::headline((string) $this->severity),
            'defect_description' => $this->defect_description,
            'notes'              => $this->notes,
            'is_active'          => (bool) $this->is_active,
            'product'            => $this->whenLoaded('product', fn () => $this->product ? [
                'id'           => $this->product->hash_id,
                'part_number'  => $this->product->part_number,
                'name'         => $this->product->name,
            ] : null),
            'creator'            => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'created_at'         => optional($this->created_at)?->toISOString(),
            'updated_at'         => optional($this->updated_at)?->toISOString(),
            'deleted_at'         => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
