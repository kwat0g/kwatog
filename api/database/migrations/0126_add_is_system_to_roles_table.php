<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Series R — Task R1.
 *
 * Adds `is_system` to roles so the UI can render the System / Custom badge
 * and the service layer can refuse mutation/deletion of seeded system roles.
 *
 * Backfills `is_system = true` for the 12 originally-seeded roles. Any role
 * created after this migration runs (via the new admin UI) is `false` by
 * default.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $seededSlugs = [
        'system_admin',
        'hr_officer',
        'finance_officer',
        'production_manager',
        'ppc_head',
        'purchasing_officer',
        'warehouse_staff',
        'qc_inspector',
        'maintenance_tech',
        'impex_officer',
        'department_head',
        'employee',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('description');
            $table->index('is_system');
        });

        DB::table('roles')
            ->whereIn('slug', $this->seededSlugs)
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['is_system']);
            $table->dropColumn('is_system');
        });
    }
};
