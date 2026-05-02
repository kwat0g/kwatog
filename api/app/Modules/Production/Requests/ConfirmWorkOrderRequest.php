<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmWorkOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.wo.confirm') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'machine_id' => Machine::class,
            'mold_id'    => Mold::class,
        ];
    }

    public function rules(): array
    {
        return [
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'mold_id'    => ['nullable', 'integer', 'exists:molds,id'],
        ];
    }
}
