<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use App\Common\Services\SettingsService;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        $features = $this->resolveEnabledFeatures();

        return [
            'id'    => $this->hash_id,
            'name'  => $this->name,
            'email' => $this->email,
            'is_active'             => (bool) $this->is_active,
            'must_change_password'  => (bool) $this->must_change_password,
            'theme_mode'            => $this->theme_mode,
            'sidebar_collapsed'     => (bool) $this->sidebar_collapsed,
            'role' => $this->whenLoaded('role', fn () => [
                'id'   => $this->role->hash_id,
                'name' => $this->role->name,
                'slug' => $this->role->slug,
            ]),
            'employee' => $this->employee_id
                ? (function () {
                    $e = \App\Modules\HR\Models\Employee::query()->whereKey($this->employee_id)->first();
                    return $e ? [
                        'id'          => $e->hash_id,
                        'employee_no' => $e->employee_no,
                        'full_name'   => $e->full_name,
                        'department_id' => optional($e->department_id) ? \App\Modules\HR\Models\Department::query()->whereKey($e->department_id)->first()?->hash_id : null,
                    ] : null;
                })()
                : null,
            'permissions' => $this->permission_slugs,
            'features'    => $features,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveEnabledFeatures(): array
    {
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $modules = [
            'hr', 'attendance', 'leave', 'payroll', 'loans', 'accounting',
            'inventory', 'purchasing', 'supply_chain', 'production',
            'mrp', 'crm', 'quality', 'maintenance',
        ];

        return array_values(array_filter(
            $modules,
            fn (string $m) => (bool) $settings->get("modules.{$m}", true),
        ));
    }
}
