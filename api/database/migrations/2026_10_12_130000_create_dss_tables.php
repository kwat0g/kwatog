<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 0-1 — DSS engine tables.
 *
 * Six engine tables plus the first DSS snapshot table (`stock_level_snapshots`,
 * process gap G-01: `stock_levels` is overwritten live with no history, so every
 * trend/cover/aging DSS rebuilding from it would silently read "now" as history).
 *
 * Timestamp-named (not 0NNN_) because this migration also writes `permissions`,
 * `role_permissions` and `settings` rows, which `2026_*` migrations touch — see
 * the ordering rule in CLAUDE.md. The DSS tables themselves only FK to ancient
 * tables (users, items, warehouse_locations).
 *
 * Dedupe invariant: one open/snoozed recommendation per (detector, dedupe_key).
 * Terminal states (accepted/dismissed/expired/executed) never block a later
 * re-occurrence — enforced by the partial unique index below, not by application
 * checks alone (two workers, one race).
 */
return new class extends Migration
{
    /**
     * slug => [display name, role slugs that hold it, grant-to-all flag].
     *
     * At initial rollout `dss.view` was granted to every role and this migration
     * mirrored that grant. Migration 2026_10_23_090000 later removes it from
     * employee and driver, who have no decisions in the DSS role matrix.
     * `dss.manage` (run-now, parameter edits) goes to the promotion approver
     * only; `dss.admin` (kill switch, detector on/off) stays system_admin-only
     * via the `*` wildcard — no explicit grant, nothing to revoke.
     */
    private const GRANTS = [
        'dss.view' => ['View Decision Center Recommendations', [], true],
        'dss.manage' => ['Run DSS Detectors & Edit Parameters', ['vice_president'], false],
        'dss.admin' => ['Manage DSS Detectors & Kill Switch', [], false],
    ];

    private const ENGINE_PARAMS = [
        'dss.engine.enabled' => true,
        'dss.engine.default_mode' => 'shadow',
        'dss.engine.alerts_per_user_per_day' => 5,
    ];

    public function up(): void
    {
        Schema::create('dss_recommendations', function (Blueprint $table) {
            $table->id();
            $table->string('detector_id', 80);
            $table->string('detector_version', 20)->default('v1');
            $table->string('domain', 50);
            $table->string('type', 50);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('severity', 20)->default('warning');
            $table->string('status', 20)->default('open');
            $table->decimal('priority_score', 8, 4)->nullable();
            $table->decimal('impact_estimate', 15, 2)->nullable();
            $table->string('impact_unit', 20)->nullable();
            $table->boolean('impact_is_estimate')->default(true);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('data_sufficiency', 20)->default('sufficient');
            $table->jsonb('rationale');
            $table->jsonb('action')->nullable();
            $table->string('dedupe_key', 150);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('dismiss_reason')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();

            $table->index('detector_id');
            $table->index('status');
            $table->index('severity');
            $table->index('expires_at');
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("
            CREATE UNIQUE INDEX dss_recommendations_open_dedupe_unique
            ON dss_recommendations (detector_id, dedupe_key)
            WHERE status IN ('open', 'snoozed')
        ");

        Schema::create('dss_recommendation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained('dss_recommendations')->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('recommendation_id');
        });

        Schema::create('dss_parameters', function (Blueprint $table) {
            $table->id();
            $table->string('detector_id', 80);
            $table->string('key', 100);
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['detector_id', 'key']);
        });

        Schema::create('dss_forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->string('detector_id', 80);
            $table->string('method', 50);
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->decimal('mape', 10, 4)->nullable();
            $table->decimal('mae', 15, 4)->nullable();
            $table->decimal('bias', 10, 4)->nullable();
            $table->integer('series_count')->default(0);
            $table->integer('data_points')->default(0);
            $table->boolean('sufficient')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('detector_id');
        });

        Schema::create('dss_scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('inputs');
            $table->jsonb('outputs')->nullable();
            $table->timestamps();
        });

        Schema::create('dss_run_logs', function (Blueprint $table) {
            $table->id();
            $table->string('detector_id', 80);
            $table->string('detector_version', 20)->default('v1');
            $table->string('status', 20);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('rows_scanned')->default(0);
            $table->integer('emitted')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('detector_id');
        });

        Schema::create('stock_level_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->decimal('quantity', 10, 3);
            $table->decimal('reserved_quantity', 10, 3)->default(0);
            $table->decimal('weighted_avg_cost', 15, 4)->nullable();
            $table->date('snapshot_date');
            $table->timestamps();

            // Mirrors stock_levels' non-null (item, location) pair: plain unique,
            // no NULLS NOT DISTINCT games needed.
            $table->unique(['item_id', 'location_id', 'snapshot_date'], 'stock_snapshots_item_loc_date_unique');
            $table->index('snapshot_date');
        });

        foreach (self::ENGINE_PARAMS as $key => $value) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'dss',
                'label' => "DSS engine: {$key}",
                'description' => 'Seeded by the DSS Wave 0-1 migration; tune via the DSS admin console.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('settings')->insertOrIgnore([
            'key' => 'modules.dss', 'value' => json_encode(true), 'group' => 'modules',
            'label' => 'Decision Support', 'description' => 'Enable or disable the Decision Support module.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (self::GRANTS as $slug => [$name, $roleSlugs, $grantAll]) {
            $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');

            if ($permissionId === null) {
                $permissionId = (int) DB::table('permissions')->insertGetId([
                    'slug' => $slug,
                    'name' => $name,
                    'module' => 'dss',
                    'description' => 'Decision Support access control.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($grantAll) {
                $roleSlugs = DB::table('roles')->pluck('slug')->all();
            }

            foreach ($roleSlugs as $roleSlug) {
                $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

                if ($roleId === null) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
                Cache::forget("auth:role_perms:{$roleId}");
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dss_recommendations_open_dedupe_unique');

        foreach (self::GRANTS as $slug => [, $roleSlugs, $grantAll]) {
            $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');

            if ($permissionId === null) {
                continue;
            }

            if ($grantAll) {
                $roleSlugs = DB::table('roles')->pluck('slug')->all();
            }
            foreach ($roleSlugs as $roleSlug) {
                $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

                if ($roleId === null) {
                    continue;
                }

                // Detach only — the rows are catalogued in RolePermissionSeeder,
                // so a rollback must not leave a catalogued slug permanently
                // unheld (same rule as the dashboard-reads migration).
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->delete();
                Cache::forget("auth:role_perms:{$roleId}");
            }
        }

        DB::table('settings')->whereIn('key', [...array_keys(self::ENGINE_PARAMS), 'modules.dss'])->delete();

        Schema::dropIfExists('stock_level_snapshots');
        Schema::dropIfExists('dss_run_logs');
        Schema::dropIfExists('dss_scenarios');
        Schema::dropIfExists('dss_forecast_runs');
        Schema::dropIfExists('dss_parameters');
        Schema::dropIfExists('dss_recommendation_events');
        Schema::dropIfExists('dss_recommendations');
    }
};
