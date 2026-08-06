<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\ReturnManagement\Enums\DispositionType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DisposeReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('return_management.manage');
    }

    public function rules(): array
    {
        return [
            'dispositions'               => ['required', 'array', 'min:1'],
            'dispositions.*.item_id'     => ['required', 'string'],
            'dispositions.*.disposition' => ['required', Rule::enum(DispositionType::class)],
            'dispositions.*.notes'       => ['nullable', 'string', 'max:500'],
            'create_replacement_po'      => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Disposition is a one-shot, irreversible step (it issues the credit note
     * and reverses GRN receipts), and the service silently skipped any line it
     * had no entry for. A partial payload therefore locked the RMA as
     * "disposed" with undecided lines that could never be revisited.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rma = $this->route('returnRequest');
            if (! $rma instanceof ReturnRequest) {
                return;
            }

            $lineIds = $rma->items()->pluck('id');
            $given   = [];

            foreach ((array) $this->input('dispositions', []) as $idx => $row) {
                $id = HashIdFilter::decode((string) ($row['item_id'] ?? ''), ReturnRequestItem::class);
                if (! $id || ! $lineIds->contains($id)) {
                    $v->errors()->add("dispositions.{$idx}.item_id", 'This line does not belong to the return request.');
                    continue;
                }
                if (in_array($id, $given, true)) {
                    $v->errors()->add("dispositions.{$idx}.item_id", 'This line appears more than once.');
                    continue;
                }
                $given[] = $id;
            }

            $missing = $lineIds->diff($given);
            if ($missing->isNotEmpty()) {
                $v->errors()->add(
                    'dispositions',
                    "Every return line needs a disposition — {$missing->count()} line(s) are undecided.",
                );
            }
        });
    }
}
