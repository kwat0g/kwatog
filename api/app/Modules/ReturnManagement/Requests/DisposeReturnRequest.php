<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\ReturnManagement\Enums\DispositionType;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DisposeReturnRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()->can('return_management.manage');
    }

    protected function hashIdFields(): array
    {
        return ['location_id' => WarehouseLocation::class];
    }

    public function rules(): array
    {
        return [
            'dispositions'               => ['required', 'array', 'min:1'],
            'dispositions.*.item_id'     => ['required', 'string'],
            'dispositions.*.disposition' => ['required', Rule::enum(DispositionType::class)],
            'dispositions.*.notes'       => ['nullable', 'string', 'max:500'],
            'create_replacement_po'      => ['sometimes', 'boolean'],
            'location_id'                => ['sometimes', 'integer', 'exists:warehouse_locations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.exists' => 'The selected warehouse location is invalid.',
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

            // 2026-08-08 — stock movement happens at disposition time on both
            // sides: customer restock/rework lines are received back into
            // stock, supplier return_to_supplier lines ship out. Either way the
            // warehouse location is mandatory when a movement line exists.
            $dispositions = (array) $this->input('dispositions', []);
            $hasMovement = collect($dispositions)->contains(fn (array $row) =>
                $rma->type === ReturnRequestType::SupplierReturn
                    ? ($row['disposition'] ?? null) === DispositionType::ReturnToSupplier->value
                    : in_array(
                        $row['disposition'] ?? null,
                        [DispositionType::Restock->value, DispositionType::Rework->value],
                        true,
                    )
            );
            if ($hasMovement && ! $this->input('location_id')) {
                $v->errors()->add(
                    'location_id',
                    $rma->type === ReturnRequestType::SupplierReturn
                        ? 'Select the warehouse location the returned goods ship out from.'
                        : 'Select the warehouse location returned restock lines are received back into.',
                );
            }
        });
    }
}
