<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\OpportunityStage;
use App\Modules\CRM\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportunityRequest extends FormRequest
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
            'lead_id'     => Lead::class,
            'assigned_to' => User::class,
        ];
    }

    public function rules(): array
    {
        return [
            'customer_id'         => ['required', 'integer', 'exists:customers,id'],
            'lead_id'             => ['nullable', 'integer', 'exists:leads,id'],
            'title'               => ['required', 'string', 'max:255'],
            'stage'               => ['nullable', Rule::enum(OpportunityStage::class)],
            'probability'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'estimated_value'     => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'assigned_to'         => ['nullable', 'integer', 'exists:users,id'],
            'notes'               => ['nullable', 'string'],
        ];
    }
}
