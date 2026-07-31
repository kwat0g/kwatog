<?php

declare(strict_types=1);

namespace App\Modules\Assets\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\Assets\Models\Asset;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssetTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assets.transfer');
    }

    /**
     * The SPA sends hashed ids. Decode before the `integer|exists` rules run —
     * otherwise every transfer from the browser 422s on `integer`, and a value
     * reaching AssetTransferService::create() undecoded would blow up on
     * `Asset::findOrFail()` (Postgres 22P02). Undecodable becomes 0 so `exists`
     * returns a clean 422.
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'asset_id'           => Asset::class,
            'from_department_id' => Department::class,
            'to_department_id'   => Department::class,
        ] as $field => $model) {
            $raw = $this->input($field);
            if ($raw === null || $raw === '') {
                continue;
            }
            $this->merge([$field => HashIdFilter::decode($raw, $model) ?? 0]);
        }
    }

    public function rules(): array
    {
        return [
            'asset_id'           => ['required', 'integer', 'exists:assets,id'],
            'from_department_id' => ['required', 'integer', 'exists:departments,id'],
            'to_department_id'   => ['required', 'integer', 'exists:departments,id', 'different:from_department_id'],
            'reason'             => ['nullable', 'string', 'max:500'],
            'transfer_date'      => ['required', 'date'],
        ];
    }
}
