<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Common\Support\HashId;
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
            'reference_id'         => $this->reference_id === null
                ? null
                : HashId::encode((int) $this->reference_id),
            'reference_label'      => $this->referenceLabel(),
            'total_debit'          => (string) $this->total_debit,
            'total_credit'         => (string) $this->total_credit,
            'status'               => $this->status?->value,
            'status_label'         => $this->status?->label(),
            // list()/show() eager-load reversedBy; the fallback encodes the
            // known FK without issuing one query per reversed row.
            'reversed_by_entry_id' => $this->reversed_by_entry_id === null
                ? null
                : ($this->relationLoaded('reversedBy')
                    ? $this->reversedBy?->hash_id
                    : HashId::encode((int) $this->reversed_by_entry_id)),
            'reversal_reason'      => $this->reversal_reason,
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
            'deleted_at'           => optional($this->deleted_at)?->toIso8601String(),
            // Journal actions are evaluated for the current viewer from the
            // same status, source-link and maker-checker guards used by the
            // service. Lists do not need per-row action policy work.
            'available_actions'    => $this->availableActions($request),
        ];
    }

    /** @return array{can_edit: bool, can_delete: bool, can_restore: bool, can_post: bool, can_reverse: bool}|null */
    private function availableActions(Request $request): ?array
    {
        $viewer = $request->user();
        if ($request->route('journalEntry') === null || $viewer === null) {
            return null;
        }

        $draft = $this->isDraft();
        $posted = $this->isPosted();
        $archived = $this->trashed();
        $canCreate = $viewer->can('accounting.journal.create');
        $differentMaker = $this->created_by === null || (int) $this->created_by !== (int) $viewer->getKey();
        $manualOrReversal = $this->reference_type === null
            || $this->reference_type === 'journal_entry_reversal';

        return [
            'can_edit' => $canCreate && $draft && ! $archived,
            'can_delete' => $canCreate && $draft && ! $archived,
            'can_restore' => $canCreate && $draft && $archived,
            'can_post' => $viewer->can('accounting.journal.post')
                && $draft
                && ! $archived
                && $this->reference_type === null
                && $differentMaker,
            'can_reverse' => $viewer->can('accounting.journal.reverse')
                && $posted
                && ! $archived
                && $this->reversed_by_entry_id === null
                && $manualOrReversal
                && $differentMaker,
        ];
    }
}
