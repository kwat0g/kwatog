<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use App\Modules\Accounting\Models\Account;
use App\Common\Concerns\ResolvesHashIds;
use Illuminate\Foundation\Http\FormRequest;

class DecideSalesOrderAdvanceRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.invoices.collect') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['bank_account_id' => Account::class];
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'reference_number' => ['nullable', 'string', 'max:120'],
        ];
    }
}
