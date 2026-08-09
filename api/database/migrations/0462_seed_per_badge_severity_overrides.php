<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-badge sidebar severity overrides (2026-08-08).
 *
 * The global dashboard.badges.{danger,warning}_threshold apply to every badge.
 * These rows let specific high-signal badges escalate earlier than the global
 * 20 — a handful of overdue bills or quarantined lots matters more than 20
 * open items in a busy queue. Keys are
 * dashboard.badges.overrides.<badge_key>.{danger,warning}; BadgeService reads
 * them from the 'dashboard' settings group on every compute, falling back to
 * the global thresholds when a badge has no override row.
 *
 * Badges without a row here simply keep using the global thresholds.
 *
 * Notes:
 *  - severity() evaluates the danger threshold FIRST, so a badge shows red
 *    above its danger count even if the warning value is set higher. The
 *    seeded pairs keep danger >= warning.
 *  - Additional badges can get overrides too: insert a row like
 *    dashboard.badges.overrides.<badge_key>.{danger,warning} in the 'dashboard'
 *    group (BadgeService reads the whole group, so no code change is needed).
 */
return new class extends Migration {
    public function up(): void
    {
        $rows = [
            [
                'key' => 'dashboard.badges.overrides.overdue_bills.danger',
                'value' => 3,
                'label' => 'Overdue Bills — danger threshold',
                'description' => 'Overdue payables at or above this count are red. (Global default: 20.)',
            ],
            [
                'key' => 'dashboard.badges.overrides.overdue_bills.warning',
                'value' => 1,
                'label' => 'Overdue Bills — warning threshold',
                'description' => 'Overdue payables above this count are amber. (Global default: 0.)',
            ],
            [
                'key' => 'dashboard.badges.overrides.mrb_holds.danger',
                'value' => 5,
                'label' => 'MRB / Quarantine — danger threshold',
                'description' => 'Quarantined lots at or above this count are red — held stock blocks production lines. (Global default: 20.)',
            ],
            [
                'key' => 'dashboard.badges.overrides.mrb_holds.warning',
                'value' => 1,
                'label' => 'MRB / Quarantine — warning threshold',
                'description' => 'Quarantined lots above this count are amber. (Global default: 0.)',
            ],
            [
                'key' => 'dashboard.badges.overrides.low_stock.danger',
                'value' => 10,
                'label' => 'Low Stock — danger threshold',
                'description' => 'Items at or below reorder point, count at or above this value is red. (Global default: 20.)',
            ],
            [
                'key' => 'dashboard.badges.overrides.low_stock.warning',
                'value' => 1,
                'label' => 'Low Stock — warning threshold',
                'description' => 'Items at or below reorder point, count above this value is amber. (Global default: 0.)',
            ],
            [
                'key' => 'dashboard.badges.overrides.work_orders.danger',
                'value' => 5,
                'label' => 'Work Orders — danger threshold',
                'description' => 'Overdue production work orders at or above this count are red. (Global default: 20.)',
            ],
            [
                'key' => 'dashboard.badges.overrides.work_orders.warning',
                'value' => 1,
                'label' => 'Work Orders — warning threshold',
                'description' => 'Overdue production work orders above this count are amber. (Global default: 0.)',
            ],
        ];

        foreach ($rows as $row) {
            DB::table('settings')->insertOrIgnore([
                ...$row,
                'value' => json_encode($row['value']),
                'group' => 'dashboard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Scoped to the rows this migration seeded — never delete overrides
        // an operator added afterwards for other badges.
        DB::table('settings')->whereIn('key', [
            'dashboard.badges.overrides.overdue_bills.danger',
            'dashboard.badges.overrides.overdue_bills.warning',
            'dashboard.badges.overrides.mrb_holds.danger',
            'dashboard.badges.overrides.mrb_holds.warning',
            'dashboard.badges.overrides.low_stock.danger',
            'dashboard.badges.overrides.low_stock.warning',
            'dashboard.badges.overrides.work_orders.danger',
            'dashboard.badges.overrides.work_orders.warning',
        ])->delete();
    }
};
