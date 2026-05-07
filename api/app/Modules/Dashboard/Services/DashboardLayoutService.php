<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Models\DashboardLayout;
use App\Modules\Dashboard\Models\DashboardWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Series R — Task R4.
 *
 * Resolves and persists dashboard layouts. Resolution order:
 *   1. Personal user layout (rows where owner_type='user', owner_id=$user->id)
 *   2. Role default       (rows where owner_type='role', owner_id=$user->role_id)
 *   3. Empty fallback
 *
 * Widgets the user can't see (their `permission` is missing from the
 * effective set) are stripped at render time so a leaky default doesn't
 * surface a forbidden widget.
 */
class DashboardLayoutService
{
    /**
     * @return array<int, array{key: string, name: string, description: ?string, module: string, x: int, y: int, w: int, h: int, source: string}>
     */
    public function getEffectiveLayout(User $user): array
    {
        $userLayout = DashboardLayout::query()
            ->where('owner_type', DashboardLayout::OWNER_USER)
            ->where('owner_id', $user->id)
            ->orderBy('position_y')
            ->orderBy('position_x')
            ->get();

        $source = 'user';
        if ($userLayout->isEmpty()) {
            $userLayout = $this->roleDefaultRows($user);
            $source = 'role';
        }
        if ($userLayout->isEmpty()) {
            return [];
        }

        $widgetMap = DashboardWidget::query()
            ->whereIn('key', $userLayout->pluck('widget_key')->unique()->all())
            ->get()
            ->keyBy('key');

        $userPerms = $user->permission_slugs;
        $isSystemAdmin = $user->role?->slug === 'system_admin';

        $rows = [];
        foreach ($userLayout as $row) {
            /** @var DashboardWidget|null $widget */
            $widget = $widgetMap->get($row->widget_key);
            if (! $widget) {
                continue;
            }

            // Strip widgets requiring a permission the user lacks (admin sees all).
            if (! $isSystemAdmin && $widget->permission && ! in_array($widget->permission, $userPerms, true)) {
                continue;
            }

            $rows[] = [
                'key'         => $widget->key,
                'name'        => $widget->name,
                'description' => $widget->description,
                'module'      => $widget->module,
                'permission'  => $widget->permission,
                'x'           => (int) $row->position_x,
                'y'           => (int) $row->position_y,
                'w'           => (int) $row->width,
                'h'           => (int) $row->height,
                'source'      => $source,
            ];
        }

        return $rows;
    }

    /** @return Collection<int, DashboardLayout> */
    private function roleDefaultRows(User $user): Collection
    {
        if (! $user->role_id) {
            return collect();
        }
        return DashboardLayout::query()
            ->where('owner_type', DashboardLayout::OWNER_ROLE)
            ->where('owner_id', $user->role_id)
            ->orderBy('position_y')
            ->orderBy('position_x')
            ->get();
    }

    /**
     * Idempotent: copies the role's default rows into user-owned rows the
     * first time it runs; later calls are no-ops.
     */
    public function cloneRoleDefaultToUser(User $user): void
    {
        if (! $user->role_id) {
            return;
        }

        DB::transaction(function () use ($user) {
            $hasUserRows = DashboardLayout::query()
                ->where('owner_type', DashboardLayout::OWNER_USER)
                ->where('owner_id', $user->id)
                ->exists();
            if ($hasUserRows) {
                return;
            }

            $roleRows = DashboardLayout::query()
                ->where('owner_type', DashboardLayout::OWNER_ROLE)
                ->where('owner_id', $user->role_id)
                ->get();
            if ($roleRows->isEmpty()) {
                return;
            }

            $now = now();
            $insert = $roleRows->map(fn (DashboardLayout $r) => [
                'owner_type' => DashboardLayout::OWNER_USER,
                'owner_id'   => $user->id,
                'widget_key' => $r->widget_key,
                'position_x' => $r->position_x,
                'position_y' => $r->position_y,
                'width'      => $r->width,
                'height'     => $r->height,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DashboardLayout::insert($insert);
        });
    }

    /**
     * @param  array<int, array{key: string, x?: int, y?: int, w?: int, h?: int}>  $widgets
     */
    public function saveUserLayout(User $user, array $widgets): void
    {
        DB::transaction(function () use ($user, $widgets) {
            DashboardLayout::query()
                ->where('owner_type', DashboardLayout::OWNER_USER)
                ->where('owner_id', $user->id)
                ->delete();

            if (empty($widgets)) {
                return;
            }

            $known = DashboardWidget::query()->pluck('key')->all();
            $now = now();
            $rows = [];
            foreach ($widgets as $i => $w) {
                if (! in_array($w['key'] ?? null, $known, true)) {
                    continue;
                }
                $rows[] = [
                    'owner_type' => DashboardLayout::OWNER_USER,
                    'owner_id'   => $user->id,
                    'widget_key' => $w['key'],
                    'position_x' => (int) ($w['x'] ?? 0),
                    'position_y' => (int) ($w['y'] ?? $i),
                    'width'      => (int) ($w['w'] ?? 12),
                    'height'     => (int) ($w['h'] ?? 4),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (! empty($rows)) {
                DashboardLayout::insert($rows);
            }
        });
    }

    public function resetUserLayout(User $user): void
    {
        DashboardLayout::query()
            ->where('owner_type', DashboardLayout::OWNER_USER)
            ->where('owner_id', $user->id)
            ->delete();
    }

    /**
     * @return array<int, array{key: string, name: string, description: ?string, module: string, permission: ?string}>
     */
    public function listAvailableWidgets(User $user): array
    {
        $isSystemAdmin = $user->role?->slug === 'system_admin';
        $userPerms = $user->permission_slugs;

        return DashboardWidget::query()
            ->orderBy('module')
            ->orderBy('key')
            ->get()
            ->filter(fn (DashboardWidget $w) =>
                $isSystemAdmin || ! $w->permission || in_array($w->permission, $userPerms, true)
            )
            ->map(fn (DashboardWidget $w) => [
                'key'         => $w->key,
                'name'        => $w->name,
                'description' => $w->description,
                'module'      => $w->module,
                'permission'  => $w->permission,
                'default_w'   => (int) $w->default_w,
                'default_h'   => (int) $w->default_h,
            ])
            ->values()
            ->all();
    }
}
