<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Models\LoginHistory;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $recent = LoginHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'id'    => $user->hash_id,
            'name'  => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'is_locked' => $user->isLocked(),
            'locked_until' => optional($user->locked_until)->toIso8601String(),
            'must_change_password' => (bool) $user->must_change_password,
            'theme_mode' => $user->theme_mode,
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
                'position'    => $user->employee->relationLoaded('position') && $user->employee->position ? [
                    'id'    => $user->employee->position->hash_id,
                    'title' => $user->employee->position->title,
                ] : null,
            ] : null,
            'last_activity' => optional($user->last_activity)->toIso8601String(),
            'password_changed_at' => optional($user->password_changed_at)->toIso8601String(),
            'created_at'    => optional($user->created_at)->toIso8601String(),
            'recent_logins' => LoginHistoryResource::collection($recent),
        ];
    }
}
