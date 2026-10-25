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
use App\Modules\ReturnManagement\Support\ReturnManagementAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DisposeReturnRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        $rma = $this->route('returnRequest');
        $user = $this->user();
        if ($rma instanceof ReturnRequest
            && $rma->type === ReturnRequestType::SupplierReturn
            && ReturnManagementAccess::customerReturnsOnly($user)) {
            return false;
        }
        if ($rma instanceof ReturnRequest && str_starts_with((string) $rma->source_key, 'grn-rejection:')
            && ! in_array($user?->role?->slug, ['purchasing_officer', 'system_admin'], true)) {
            return false;
        }

        return $user?->can('return_management.dispose') === true
            || $user?->can('return_management.manage') === true;
    }

    protected function hashIdFields(): array
    {
        return ['location_id' => WarehouseLocation::class];
    }

    public function rules(): array
    {
        $rma = $this->route('returnRequest');
        $replacementRequired = $rma instanceof ReturnRequest
            && $rma->type === ReturnRequestType::SupplierReturn
            && $this->boolean('create_replacement_po');
        $allowedDispositions = $rma instanceof ReturnRequest
            ? (str_starts_with((string) $rma->source_key, 'grn-rejection:')
                ? [DispositionType::ReturnToSupplier->value]
                : array_map(
                    static fn (DispositionType $disposition): string => $disposition->value,
                    DispositionType::allowedFor($rma->type, (bool) $rma->finance_only),
                ))
            : array_map(static fn (DispositionType $disposition): string => $disposition->value, DispositionType::cases());

        return [
            'dispositions' => ['required', 'array', 'min:1'],
            'dispositions.*.item_id' => ['required', 'string'],
            'dispositions.*.disposition' => ['required', Rule::in($allowedDispositions)],
            'dispositions.*.notes' => ['nullable', 'string', 'max:500'],
            'create_replacement_po' => ['sometimes', 'boolean'],
            'required_delivery_date' => [Rule::requiredIf($replacementRequired), 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'location_id' => ['sometimes', 'integer', 'exists:warehouse_locations,id'],
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
            $given = [];

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

            // Customer restock/rework waits for a separate MRB release, which
            // chooses its own destination. Supplier returns still ship at
            // disposition and need a source location here.
            $dispositions = (array) $this->input('dispositions', []);
            $rma->loadMissing('items');
            $hasMovement = $rma->type === ReturnRequestType::SupplierReturn
                && collect($dispositions)->contains(function (array $row) use ($rma): bool {
                    if (($row['disposition'] ?? null) !== DispositionType::ReturnToSupplier->value) {
                        return false;
                    }
                    $line = $rma->items->firstWhere('id', HashIdFilter::decode((string) ($row['item_id'] ?? ''), ReturnRequestItem::class));

                    return $line && bccomp((string) $line->stock_movement_quantity, '0', 3) <= 0;
                });
            if ($hasMovement && ! $this->input('location_id')) {
                $v->errors()->add(
                    'location_id',
                    'Select the warehouse location the returned goods ship out from.',
                );
            }
        });
    }
}
