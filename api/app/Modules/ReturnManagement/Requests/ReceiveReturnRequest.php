<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\ReturnManagement\Models\ReturnReceipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Receipt of physically returned goods.
 *
 * `received_quantities` is a map of return-line hash_id → quantity. The old
 * controller passed the map straight through and the service looked lines up by
 * raw integer PK, which the SPA never sees — so the per-line returned quantity
 * silently stayed at zero for every RMA and every downstream credit / restock
 * used the *requested* quantity instead.
 */
class ReceiveReturnRequest extends FormRequest
{
    use ResolvesHashIds;
    public function authorize(): bool
    {
        return $this->user()?->can('return_management.receive') === true
            || $this->user()?->can('return_management.manage') === true;
    }

    public function rules(): array
    {
        return [
            'received_quantities'   => ['nullable', 'array'],
            // Keep quantities decimal-safe before the after-validator calls
            // bccomp; scientific notation and >3 decimal places must return a
            // normal 422 instead of reaching arbitrary-precision functions.
            'received_quantities.*' => ['numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,3})?$/'],
            'quarantine_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'final_receipt' => ['sometimes', 'boolean'],
            'request_key' => ['nullable', 'uuid'],
        ];
    }

    protected function hashIdFields(): array
    {
        return ['quarantine_location_id' => WarehouseLocation::class];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rma = $this->route('returnRequest');
            if (! $rma instanceof ReturnRequest) {
                return;
            }

            // Field rules above must finish before arithmetic validation. In
            // particular, arrays and scientific notation can otherwise reach
            // bccomp through the raw input and become a 500 response.
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $lines = $rma->items()->get()->keyBy('id');
            $isFinal = $this->boolean('final_receipt', true);
            $requestKey = trim((string) $this->input('request_key', ''));
            $existingReceipt = $requestKey !== ''
                ? ReturnReceipt::query()
                    ->where('return_request_id', $rma->id)
                    ->where('request_key', $requestKey)
                    ->exists()
                : false;
            $legacyFinalReplay = $requestKey === ''
                && $isFinal
                && $rma->received_at !== null
                && ReturnReceipt::query()
                    ->where('return_request_id', $rma->id)
                    ->whereNull('request_key')
                    ->where('final_receipt', true)
                    ->exists();

            if ((! $isFinal || $rma->received_at !== null) && $requestKey === '' && ! $legacyFinalReplay) {
                $v->errors()->add('request_key', 'A request key is required for partial or subsequent return receipts.');
            }
            if (! $isFinal && ! is_array($this->input('received_quantities'))) {
                $v->errors()->add('received_quantities', 'Enter the quantities physically received for this partial receipt.');
            }

            $hasPositiveQuantity = false;
            foreach ((array) $this->input('received_quantities', []) as $hashId => $qty) {
                $id   = HashIdFilter::decode((string) $hashId, ReturnRequestItem::class);
                $line = $id ? $lines->get($id) : null;

                if (! $line) {
                    $v->errors()->add("received_quantities.{$hashId}", 'This line does not belong to the return request.');
                    continue;
                }

                if (bccomp((string) $qty, '0', 3) > 0) {
                    $hasPositiveQuantity = true;
                }
                if ($existingReceipt || $legacyFinalReplay) {
                    // The service compares the request fingerprint before the
                    // normal remaining-quantity guard, making an exact retry safe.
                    continue;
                }

                $remaining = bcsub((string) $line->quantity, (string) $line->returned_quantity, 3);
                if (bccomp((string) $qty, $remaining, 3) > 0) {
                    $v->errors()->add(
                        "received_quantities.{$hashId}",
                        "Received quantity cannot exceed the remaining requested quantity of {$remaining}.",
                    );
                }
            }

            if (! $isFinal && ! $hasPositiveQuantity) {
                $v->errors()->add('received_quantities', 'A partial receipt must include a positive quantity.');
            }
        });
    }

    /**
     * @return array<int, string> map of return_request_items.id → quantity
     */
    public function receivedQuantitiesById(): array
    {
        $out = [];
        foreach ((array) $this->validated()['received_quantities'] ?? [] as $hashId => $qty) {
            $id = HashIdFilter::decode((string) $hashId, ReturnRequestItem::class);
            if ($id) {
                $out[$id] = (string) $qty;
            }
        }

        return $out;
    }

    public function quarantineLocationId(): ?int
    {
        return isset($this->validated()['quarantine_location_id'])
            ? (int) $this->validated()['quarantine_location_id']
            : null;
    }

    public function finalReceipt(): bool
    {
        return $this->boolean('final_receipt', true);
    }

    public function requestKey(): ?string
    {
        $key = trim((string) ($this->validated()['request_key'] ?? ''));

        return $key !== '' ? strtolower($key) : null;
    }
}
