<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOpportunityRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.opportunities.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'customer_id' => Customer::class,
            'assigned_to' => User::class,
        ];
    }

    public function rules(): array
    {
        return [
            'customer_id'         => ['sometimes', 'integer', 'exists:customers,id'],
            'title'               => ['sometimes', 'string', 'max:255'],
            'probability'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'estimated_value'     => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'assigned_to'         => ['nullable', 'integer', 'exists:users,id'],
            'notes'               => ['nullable', 'string'],
        ];
    }
}
