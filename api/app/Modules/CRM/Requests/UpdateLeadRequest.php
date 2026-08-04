<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\LeadSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.leads.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'assigned_to' => User::class,
            'customer_id' => Customer::class,
        ];
    }

    public function rules(): array
    {
        return [
            'company_name'    => ['sometimes', 'string', 'max:255'],
            'contact_person'  => ['sometimes', 'string', 'max:255'],
            'email'           => ['nullable', 'email', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'source'          => ['sometimes', Rule::enum(LeadSource::class)],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string'],
            'assigned_to'     => ['nullable', 'integer', 'exists:users,id'],
            'customer_id'     => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }
}
