<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Auth\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserListResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id'    => $user->hash_id,
            'name'  => $user->name,
            'email' => $user->email,
            'status' => $this->derivedStatus($user),
            'is_active' => (bool) $user->is_active,
            'is_locked' => $user->isLocked(),
            'must_change_password' => (bool) $user->must_change_password,
            'role' => $user->role ? [
                'id'   => $user->role->hash_id,
                'name' => $user->role->name,
                'slug' => $user->role->slug,
            ] : null,
            'employee' => $user->relationLoaded('employee') && $user->employee ? [
                'id'          => $user->employee->hash_id,
                'employee_no' => $user->employee->employee_no,
                'full_name'   => $user->employee->full_name,
                'department'  => $user->employee->relationLoaded('department') && $user->employee->department ? [
                    'id'   => $user->employee->department->hash_id,
                    'name' => $user->employee->department->name,
                ] : null,
            ] : null,
            'last_activity' => optional($user->last_activity)->toIso8601String(),
            'created_at'    => optional($user->created_at)->toIso8601String(),
        ];
    }

    private function derivedStatus(User $user): string
    {
        if (! $user->is_active) {
            return 'inactive';
        }
        if ($user->isLocked()) {
            return 'locked';
        }
        return 'active';
    }
}
