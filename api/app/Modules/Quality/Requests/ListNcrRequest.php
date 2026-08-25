<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListNcrRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.ncr.view') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'product_id'    => Product::class,
            'inspection_id' => Inspection::class,
        ];
    }

    public function rules(): array
    {
        return [
            'source'        => ['nullable', Rule::in(NcrSource::values())],
            'severity'      => ['nullable', Rule::in(NcrSeverity::values())],
            'status'        => ['nullable', Rule::in(NcrStatus::values())],
            'disposition'   => ['nullable', Rule::in(NcrDisposition::values())],
            'product_id'    => ['nullable', 'integer', 'exists:products,id'],
            'inspection_id' => ['nullable', 'integer', 'exists:inspections,id'],
            'search'        => ['nullable', 'string', 'max:120'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
