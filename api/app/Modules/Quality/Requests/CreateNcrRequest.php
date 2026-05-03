<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateNcrRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.ncr.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id'    => Product::class,
            'inspection_id' => Inspection::class,
            'assigned_to'   => User::class,
        ];
    }

    public function rules(): array
    {
        return [
            'source'             => ['required', Rule::in(NcrSource::values())],
            'severity'           => ['required', Rule::in(NcrSeverity::values())],
            'product_id'         => ['nullable', 'integer', 'exists:products,id'],
            'inspection_id'      => ['nullable', 'integer', 'exists:inspections,id'],
            'defect_description' => ['required', 'string', 'max:5000'],
            'affected_quantity'  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'assigned_to'        => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
