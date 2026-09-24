<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequestForQuoteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('invitations')) {
            $invitations = [];
            foreach ((array) $this->input('invitations', []) as $row) {
                $id = HashIdFilter::decode((string) ($row['vendor_id'] ?? ''), Vendor::class) ?? (int) ($row['vendor_id'] ?? 0);
                $invitations[] = [...(array) $row, 'vendor_id' => $id];
            }
            $this->merge(['invitations' => $invitations]);
        }
        foreach (['specifications', 'required_delivery_dates'] as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $byLine = [];
            foreach ((array) $this->input($key, []) as $lineId => $value) {
                $id = HashIdFilter::decode((string) $lineId, PurchaseRequestItem::class) ?? (int) $lineId;
                if ($id) {
                    $byLine[$id] = $value;
                }
            }
            $this->merge([$key => $byLine]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.rfq.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'closes_at' => ['required', 'date', 'after:now'],
            'publish' => ['sometimes', 'boolean'],
            'invitations' => ['required', 'array', 'min:1'],
            'invitations.*.vendor_id' => ['required', 'integer', 'distinct', 'exists:vendors,id'],
            'invitations.*.exception_reason' => ['nullable', 'string', 'max:2000'],
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['nullable', 'string', 'max:2000'],
            'required_delivery_dates' => ['nullable', 'array'],
            'required_delivery_dates.*' => ['nullable', 'date', 'after:today'],
        ];
    }
}
