<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\NcrActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddNcrActionRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.ncr.manage') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['owner_id' => User::class];
    }

    public function rules(): array
    {
        return [
            'action_type'  => ['required', Rule::in(NcrActionType::values())],
            'description'  => ['required', 'string', 'max:5000'],
            'performed_at' => ['nullable', 'date'],
            'owner_id'     => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(static fn ($query) => $query->where('is_active', true)),
            ],
            'due_date'     => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
