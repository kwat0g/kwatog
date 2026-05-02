<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->hash_id,
            'entry_number'         => $this->entry_number,
            'date'                 => optional($this->date)->toDateString(),
            'description'          => $this->description,
            'reference_type'       => $this->reference_type,
            'reference_id'         => $this->reference_id,
            'reference_label'      => $this->referenceLabel(),
            'total_debit'          => (string) $this->total_debit,
            'total_credit'         => (string) $this->total_credit,
            'status'               => $this->status?->value,
            'reversed_by_entry_id' => $this->reversed_by_entry_id
                ? JournalEntry::find($this->reversed_by_entry_id)?->hash_id
                : null,
            'reversed_by_number'   => $this->whenLoaded('reversedBy', fn () => $this->reversedBy?->entry_number),
            'created_by'           => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id ?? null,
                'name' => $this->creator->name,
            ] : null),
            'posted_by'            => $this->whenLoaded('poster', fn () => $this->poster ? [
                'id'   => $this->poster->hash_id ?? null,
                'name' => $this->poster->name,
            ] : null),
            'posted_at'            => optional($this->posted_at)->toIso8601String(),
            'lines'                => JournalEntryLineResource::collection($this->whenLoaded('lines')),
            'created_at'           => optional($this->created_at)->toIso8601String(),
            'updated_at'           => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
