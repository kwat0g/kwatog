<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Production\Models\DefectType;
use Illuminate\Foundation\Http\FormRequest;

class RecordOutputRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.wo.record') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['defects.*.defect_type_id' => DefectType::class];
    }

    public function rules(): array
    {
        return [
            'good_count'              => ['required', 'integer', 'min:0'],
            'reject_count'            => ['required', 'integer', 'min:0'],
            'shift'                   => ['nullable', 'string', 'max:20'],
            'remarks'                 => ['nullable', 'string', 'max:500'],
            'defects'                 => ['nullable', 'array'],
            'defects.*.defect_type_id' => ['required_with:defects', 'integer', 'exists:defect_types,id'],
            'defects.*.count'         => ['required_with:defects', 'integer', 'min:1'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $good   = (int) $this->input('good_count');
            $reject = (int) $this->input('reject_count');
            if ($good + $reject <= 0) {
                $v->errors()->add('good_count', 'At least one of good_count or reject_count must be positive.');
            }
        });
    }
}
