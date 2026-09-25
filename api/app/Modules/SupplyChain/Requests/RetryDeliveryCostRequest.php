<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetryDeliveryCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.journals.post') ?? false;
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['customer_cogs', 'unaccounted_loss'])]];
    }
}
