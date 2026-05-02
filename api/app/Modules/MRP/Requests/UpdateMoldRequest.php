<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMoldRequest extends FormRequest
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
        $id = $this->route('mold')?->id;
        return [
            'mold_code'                    => ['sometimes', 'required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/',
                                                Rule::unique('molds', 'mold_code')->ignore($id)],
            'name'                         => ['sometimes', 'required', 'string', 'max:100'],
            'product_id'                   => ['sometimes', 'required', 'integer', 'exists:products,id'],
            'cavity_count'                 => ['sometimes', 'required', 'integer', 'min:1'],
            'cycle_time_seconds'           => ['sometimes', 'required', 'integer', 'min:1'],
            'output_rate_per_hour'         => ['sometimes', 'required', 'integer', 'min:1'],
            'setup_time_minutes'           => ['nullable', 'integer', 'min:0'],
            'max_shots_before_maintenance' => ['sometimes', 'required', 'integer', 'min:1'],
            'lifetime_max_shots'           => ['sometimes', 'required', 'integer', 'min:1'],
            'location'                     => ['nullable', 'string', 'max:50'],
        ];
    }
}
