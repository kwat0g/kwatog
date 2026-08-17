<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Exceptions\DashboardLayoutConflictException;
use App\Modules\Dashboard\Enums\RenderKind;
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
    public function __construct(
        private readonly WidgetAnalyticsService $analytics,
    ) {}

    /**
     * @return array<int, array{key: string, name: string, description: ?string, module: string, render_kind: string, link_path: ?string, x: int, y: int, w: int, h: int, source: string}>
     */
    public function getEffectiveLayout(User $user): array
    {
        $userLayout = DashboardLayout::query()
            ->where('owner_type', DashboardLayout::OWNER_USER)
            ->where('owner_id', $user->id)
            ->orderBy('position_y')
            ->orderBy('position_x')
            ->get();

        // A personal layout can outlive a permission change. If every saved
        // row becomes forbidden, fall back to the role layout instead of
        // presenting a blank dashboard. An intentionally empty layout is
        // represented by no user rows and therefore follows the same role
        // default path.
        if ($userLayout->isNotEmpty()) {
            $resolved = $this->hydrateVisibleLayout($user, $userLayout, 'user');
            if ($resolved !== []) {
                return $resolved;
            }
        }

        return $this->hydrateVisibleLayout($user, $this->roleDefaultRows($user), 'role');
    }

    /**
     * The effective layout with each widget's rich payload attached.
     *
     * Deliberately built ON TOP of getEffectiveLayout rather than beside it:
     * the permission strip lives there, so rich mode cannot widen access by
     * construction. A widget with no rich payload gets `data => null` and the
     * SPA renders the scalar it always did.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRichLayout(User $user): array
    {
        return array_map(function (array $row) use ($user): array {
            $kind = RenderKind::fromNullable($row['render_kind']);
            $payload = $this->analytics->payload($row['key'], $kind, $user);
            $row['data'] = $payload === [] ? null : $payload;

            return $row;
        }, $this->getEffectiveLayout($user));
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
    public function saveUserLayout(User $user, array $widgets, string $expectedVersion): void
    {
        DB::transaction(function () use ($user, $widgets, $expectedVersion) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $currentVersion = $this->userLayoutVersion($user);
            if (! hash_equals($currentVersion, $expectedVersion)) {
                throw new DashboardLayoutConflictException($currentVersion);
            }

            DashboardLayout::query()
                ->where('owner_type', DashboardLayout::OWNER_USER)
                ->where('owner_id', $user->id)
                ->delete();

            if (empty($widgets)) {
                return;
            }

            $allowed = DashboardWidget::query()
                ->get()
                ->filter(fn (DashboardWidget $widget): bool =>
                    $widget->permission === null || $user->hasPermission($widget->permission)
                )
                ->keyBy('key');
            $now = now();
            $rows = [];
            $seen = [];
            foreach ($widgets as $i => $w) {
                $key = (string) ($w['key'] ?? '');
                if ($key === '' || isset($seen[$key]) || ! $allowed->has($key)) {
                    continue;
                }

                $seen[$key] = true;
                $width = max(1, min(12, (int) ($w['w'] ?? $allowed->get($key)->default_w ?? 4)));
                $height = max(1, min(24, (int) ($w['h'] ?? $allowed->get($key)->default_h ?? 4)));
                $positionX = max(0, min(12 - $width, (int) ($w['x'] ?? 0)));
                $rows[] = [
                    'owner_type' => DashboardLayout::OWNER_USER,
                    'owner_id'   => $user->id,
                    'widget_key' => $key,
                    'position_x' => $positionX,
                    'position_y' => max(0, min(65535, (int) ($w['y'] ?? $i))),
                    'width'      => $width,
                    'height'     => $height,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (! empty($rows)) {
                DashboardLayout::insert($rows);
            }
        });
    }

    public function resetUserLayout(User $user, string $expectedVersion): void
    {
        DB::transaction(function () use ($user, $expectedVersion): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $currentVersion = $this->userLayoutVersion($user);
            if (! hash_equals($currentVersion, $expectedVersion)) {
                throw new DashboardLayoutConflictException($currentVersion);
            }

            DashboardLayout::query()
                ->where('owner_type', DashboardLayout::OWNER_USER)
                ->where('owner_id', $user->id)
                ->delete();
        });
    }

    /** Version only the caller's personal rows; role-default changes do not masquerade as user edits. */
    public function userLayoutVersion(User $user): string
    {
        $canonical = DashboardLayout::query()
            ->where('owner_type', DashboardLayout::OWNER_USER)
            ->where('owner_id', $user->id)
            ->orderBy('widget_key')
            ->get(['widget_key', 'position_x', 'position_y', 'width', 'height'])
            ->map(fn (DashboardLayout $row): array => [
                $row->widget_key,
                (int) $row->position_x,
                (int) $row->position_y,
                (int) $row->width,
                (int) $row->height,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<int, array{key: string, name: string, description: ?string, module: string, permission: ?string, render_kind: string, link_path: ?string, default_w: int, default_h: int}>
     */
    public function listAvailableWidgets(User $user): array
    {
        return DashboardWidget::query()
            ->orderBy('module')
            ->orderBy('key')
            ->get()
            ->filter(fn (DashboardWidget $w) =>
                $w->permission === null || $user->hasPermission($w->permission)
            )
            ->map(fn (DashboardWidget $w) => [
                'key'         => $w->key,
                'name'        => $w->name,
                'description' => $w->description,
                'module'      => $w->module,
                'permission'  => $w->permission,
                'render_kind' => $w->render_kind->value,
                'link_path'   => $w->link_path,
                'default_w'   => (int) $w->default_w,
                'default_h'   => (int) $w->default_h,
            ])
            ->values()
            ->all();
    }

    /**
     * Resolve metadata only after applying the caller's current permissions.
     * Keeping this in one helper makes the plain and rich layout paths share
     * the exact same visibility boundary.
     *
     * @param Collection<int, DashboardLayout> $layout
     * @return array<int, array{key: string, name: string, description: ?string, module: string, render_kind: string, link_path: ?string, x: int, y: int, w: int, h: int, source: string}>
     */
    private function hydrateVisibleLayout(User $user, Collection $layout, string $source): array
    {
        if ($layout->isEmpty()) {
            return [];
        }

        $widgetMap = DashboardWidget::query()
            ->whereIn('key', $layout->pluck('widget_key')->unique()->all())
            ->get()
            ->keyBy('key');

        $rows = [];
        foreach ($layout as $row) {
            /** @var DashboardWidget|null $widget */
            $widget = $widgetMap->get($row->widget_key);
            if (! $widget || ($widget->permission !== null && ! $user->hasPermission($widget->permission))) {
                continue;
            }

            $rows[] = [
                'key'         => $widget->key,
                'name'        => $widget->name,
                'description' => $widget->description,
                'module'      => $widget->module,
                'permission'  => $widget->permission,
                'render_kind' => $widget->render_kind->value,
                // The widget's own "Open →" target. Previously a hard-coded map
                // in the SPA with nothing binding it to this table.
                'link_path'   => $widget->link_path,
                'x'           => (int) $row->position_x,
                'y'           => (int) $row->position_y,
                'w'           => (int) $row->width,
                'h'           => (int) $row->height,
                'source'      => $source,
            ];
        }

        return $rows;
    }
}
