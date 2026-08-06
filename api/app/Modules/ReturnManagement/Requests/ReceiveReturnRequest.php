<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
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
    public function authorize(): bool
    {
        return $this->user()?->can('return_management.manage') === true;
    }

    public function rules(): array
    {
        return [
            'received_quantities'   => ['nullable', 'array'],
            'received_quantities.*' => ['numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rma = $this->route('returnRequest');
            if (! $rma instanceof ReturnRequest) {
                return;
            }

            $lines = $rma->items()->get()->keyBy('id');

            foreach ((array) $this->input('received_quantities', []) as $hashId => $qty) {
                $id   = HashIdFilter::decode((string) $hashId, ReturnRequestItem::class);
                $line = $id ? $lines->get($id) : null;

                if (! $line) {
                    $v->errors()->add("received_quantities.{$hashId}", 'This line does not belong to the return request.');
                    continue;
                }
                if (bccomp((string) $qty, (string) $line->quantity, 3) > 0) {
                    $v->errors()->add(
                        "received_quantities.{$hashId}",
                        "Received quantity cannot exceed the requested quantity of {$line->quantity}.",
                    );
                }
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
}
