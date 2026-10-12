<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WIDGETS = [
        'sales.order_intake' => ['Sales Order Intake', 'trend', '/crm/sales-orders', 6],
        'sales.open_orders' => ['Open Sales Orders', 'breakdown', '/crm/sales-orders', 6],
        'sales.price_requests' => ['Open Price Requests', 'breakdown', '/crm/price-agreements/requests', 6],
    ];

    public function up(): void
    {
        foreach (self::WIDGETS as $key => [$name, $kind, $link, $width]) {
            $values = [
                'name' => $name,
                'description' => $key === 'sales.order_intake'
                    ? 'Sales order count by order date over the last 12 months.'
                    : null,
                'module' => 'crm',
                'permission' => $key === 'sales.price_requests'
                    ? 'crm.price_agreements.view'
                    : 'crm.sales_orders.view',
                'permission_any' => null,
                'render_kind' => $kind,
                'link_path' => $link,
                'default_w' => $width,
                'default_h' => 4,
                'updated_at' => now(),
            ];

            if (DB::table('dashboard_widgets')->where('key', $key)->exists()) {
                DB::table('dashboard_widgets')->where('key', $key)->update($values);
            } else {
                DB::table('dashboard_widgets')->insert($values + [
                    'key' => $key,
                    'created_at' => now(),
                ]);
            }
        }

        $salesRoleId = DB::table('roles')->where('slug', 'sales_officer')->value('id');
        if ($salesRoleId === null) {
            return;
        }

        DB::table('dashboard_widgets')->where('key', 'crm.open_complaints')->update([
            'permission' => 'crm.complaints.view',
            'updated_at' => now(),
        ]);
        DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->where('owner_id', $salesRoleId)
            ->where('widget_key', 'crm.open_complaints')
            ->delete();

        foreach (array_keys(self::WIDGETS) as $key) {
            $exists = DB::table('dashboard_layouts')
                ->where('owner_type', 'role')
                ->where('owner_id', $salesRoleId)
                ->where('widget_key', $key)
                ->exists();
            if (! $exists) {
                $positionY = (int) (DB::table('dashboard_layouts')
                    ->where('owner_type', 'role')
                    ->where('owner_id', $salesRoleId)
                    ->max('position_y') ?? -1) + 1;

                DB::table('dashboard_layouts')->insert([
                    'owner_type' => 'role',
                    'owner_id' => $salesRoleId,
                    'widget_key' => $key,
                    'position_x' => 0,
                    'position_y' => $positionY,
                    'width' => self::WIDGETS[$key][3],
                    'height' => 4,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->repackSalesRoleLayout((int) $salesRoleId);
        $this->addTrendToSavedSalesLayouts((int) $salesRoleId);
    }

    public function down(): void
    {
        DB::table('dashboard_layouts')
            ->where('widget_key', 'sales.order_intake')
            ->delete();
        DB::table('dashboard_widgets')->where('key', 'sales.order_intake')->delete();

        foreach (['sales.open_orders', 'sales.price_requests'] as $key) {
            DB::table('dashboard_widgets')->where('key', $key)->update([
                'render_kind' => 'scalar',
                'default_w' => 4,
                'description' => null,
                'updated_at' => now(),
            ]);
        }

        $salesRoleId = DB::table('roles')->where('slug', 'sales_officer')->value('id');
        if ($salesRoleId !== null) {
            DB::table('dashboard_widgets')->where('key', 'crm.open_complaints')->update([
                'permission' => 'crm.view',
                'updated_at' => now(),
            ]);
            if (! DB::table('dashboard_layouts')
                ->where('owner_type', 'role')
                ->where('owner_id', $salesRoleId)
                ->where('widget_key', 'crm.open_complaints')
                ->exists()) {
                $positionY = (int) (DB::table('dashboard_layouts')
                    ->where('owner_type', 'role')
                    ->where('owner_id', $salesRoleId)
                    ->max('position_y') ?? -1) + 1;
                DB::table('dashboard_layouts')->insert([
                    'owner_type' => 'role',
                    'owner_id' => $salesRoleId,
                    'widget_key' => 'crm.open_complaints',
                    'position_x' => 0,
                    'position_y' => $positionY,
                    'width' => 6,
                    'height' => 4,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->repackSalesRoleLayout((int) $salesRoleId);
        }
    }

    private function repackSalesRoleLayout(int $roleId): void
    {
        $rows = DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->where('owner_id', $roleId)
            ->orderBy('position_y')
            ->orderBy('position_x')
            ->get(['id', 'widget_key']);

        $priority = array_flip(['sales.order_intake', 'sales.open_orders', 'sales.price_requests']);
        $rows = $rows->sortBy(fn ($row, $index): array => [
            isset($priority[$row->widget_key]) ? 0 : 1,
            $priority[$row->widget_key] ?? $index,
        ])->values();

        $x = 0;
        $y = 0;
        foreach ($rows as $row) {
            $width = (int) (DB::table('dashboard_widgets')->where('key', $row->widget_key)->value('default_w') ?? 12);
            if ($x + $width > 12) {
                $x = 0;
                $y++;
            }

            DB::table('dashboard_layouts')->where('id', $row->id)->update([
                'position_x' => $x,
                'position_y' => $y,
                'width' => $width,
            ]);
            $x += $width;
        }
    }

    private function addTrendToSavedSalesLayouts(int $roleId): void
    {
        $userIds = DB::table('users')->where('role_id', $roleId)->pluck('id');
        foreach ($userIds as $userId) {
            $layout = DB::table('dashboard_layouts')
                ->where('owner_type', 'user')
                ->where('owner_id', $userId);
            if (! $layout->whereIn('widget_key', ['sales.open_orders', 'sales.price_requests'])->exists()) {
                continue;
            }

            if (DB::table('dashboard_layouts')
                ->where('owner_type', 'user')
                ->where('owner_id', $userId)
                ->where('widget_key', 'sales.order_intake')
                ->exists()) {
                continue;
            }

            $positionY = (int) (DB::table('dashboard_layouts')
                ->where('owner_type', 'user')
                ->where('owner_id', $userId)
                ->max('position_y') ?? -1) + 1;

            DB::table('dashboard_layouts')->insert([
                'owner_type' => 'user',
                'owner_id' => $userId,
                'widget_key' => 'sales.order_intake',
                'position_x' => 0,
                'position_y' => $positionY,
                'width' => self::WIDGETS['sales.order_intake'][3],
                'height' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
