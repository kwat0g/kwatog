<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Enums\MoldStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMoldRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.molds.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['product_id' => Product::class];
    }

    public function rules(): array
    {
        return [
            'mold_code'                    => ['required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/', 'unique:molds,mold_code'],
            'name'                         => ['required', 'string', 'max:100'],
            'product_id'                   => ['required', 'integer', 'exists:products,id'],
            'cavity_count'                 => ['required', 'integer', 'min:1', 'max:512'],
            'cycle_time_seconds'           => ['required', 'integer', 'min:1', 'max:3600'],
            'output_rate_per_hour'         => ['required', 'integer', 'min:1'],
            'setup_time_minutes'           => ['nullable', 'integer', 'min:0', 'max:1440'],
            'max_shots_before_maintenance' => ['required', 'integer', 'min:1'],
            'lifetime_max_shots'           => ['required', 'integer', 'min:1'],
            'status'                       => ['nullable', Rule::in(MoldStatus::values())],
            'location'                     => ['nullable', 'string', 'max:50'],
        ];
    }
}
