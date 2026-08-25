<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;

class AssignDeliveryRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('supply_chain.deliveries.create') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'vehicle_id' => Vehicle::class,
            'driver_id' => User::class,
        ];
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'driver_id' => [
                'required',
                'integer',
                'exists:users,id',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    $isActiveDriver = User::query()
                        ->whereKey($value)
                        ->where('is_active', true)
                        ->whereHas('role', static fn ($query) => $query->where('slug', 'driver'))
                        ->exists();

                    if (! $isActiveDriver) {
                        $fail('The selected driver is not an active driver.');
                    }
                },
            ],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
