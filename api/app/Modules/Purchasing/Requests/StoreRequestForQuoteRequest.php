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
        $invitations = [];
        foreach ((array) $this->input('invitations', []) as $row) {
            $id = HashIdFilter::decode((string) ($row['vendor_id'] ?? ''), Vendor::class) ?? (int) ($row['vendor_id'] ?? 0);
            $invitations[] = [...$row, 'vendor_id' => $id];
        }
        $specifications = [];
        foreach ((array) $this->input('specifications', []) as $key => $value) {
            $id = HashIdFilter::decode((string) $key, PurchaseRequestItem::class) ?? (int) $key;
            if ($id) {
                $specifications[$id] = $value;
            }
        }
        $partial = [];
        foreach ((array) $this->input('allow_partial_quantity', []) as $key => $value) {
            $id = HashIdFilter::decode((string) $key, PurchaseRequestItem::class) ?? (int) $key;
            if ($id) {
                $partial[$id] = $value;
            }
        }
        $requiredDates = [];
        foreach ((array) $this->input('required_delivery_dates', []) as $key => $value) {
            $id = HashIdFilter::decode((string) $key, PurchaseRequestItem::class) ?? (int) $key;
            if ($id) {
                $requiredDates[$id] = $value;
            }
        }
        $this->merge([
            'invitations' => $invitations,
            'specifications' => $specifications,
            'allow_partial_quantity' => $partial,
            'required_delivery_dates' => $requiredDates,
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.rfq.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'closes_at' => ['required', 'date', 'after:now'],
            'invitations' => ['required', 'array', 'min:1'],
            'invitations.*.vendor_id' => ['required', 'integer', 'distinct', 'exists:vendors,id'],
            'invitations.*.exception_reason' => ['nullable', 'string', 'max:2000'],
            'specifications' => ['nullable', 'array'],
            'allow_partial_quantity' => ['nullable', 'array'],
            'required_delivery_dates' => ['nullable', 'array'],
            'required_delivery_dates.*' => ['nullable', 'date', 'after:today'],
        ];
    }
}
