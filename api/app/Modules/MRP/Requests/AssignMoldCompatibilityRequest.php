<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\MRP\Models\Machine;
use Illuminate\Foundation\Http\FormRequest;

class AssignMoldCompatibilityRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.molds.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['machine_ids.*' => Machine::class];
    }

    public function rules(): array
    {
        return [
            'machine_ids'   => ['required', 'array'],
            'machine_ids.*' => ['integer', 'exists:machines,id'],
        ];
    }
}
