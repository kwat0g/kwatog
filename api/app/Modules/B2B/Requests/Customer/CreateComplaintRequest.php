<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use App\Common\Support\HashIdFilter;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Foundation\Http\FormRequest;

class CreateComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var \App\Modules\B2B\Models\CustomerPortalUser $user */
        $user = $this->user('customer_portal');

        return [
            'order_id' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    $decoded = HashIdFilter::decode($value, SalesOrder::class);
                    $order   = $decoded ? SalesOrder::find($decoded) : null;
                    if (! $order || $order->customer_id !== $user?->customer_id) {
                        $fail('The order ID is invalid or does not belong to your account.');
                    }
                },
            ],
            'severity'          => ['required', 'string', 'in:minor,major,critical'],
            'description'       => ['required', 'string', 'max:2000'],
            'affected_quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
