<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a widget's "Open →" affordance goes.
 *
 * This lived in the SPA as a 51-entry `WIDGET_LINKS: Record<string, string>`
 * literal (spa/src/components/dashboard/registry.tsx). Nothing bound it to
 * `dashboard_widgets`, so a key added by the seeder silently rendered a tile
 * with no way out of it, and a key removed left a dead entry nobody noticed.
 * Moving the target onto the registry row makes the drift impossible: the
 * widget that declares its permission and its shape now also declares its
 * destination, and WidgetSeedIntegrityTest asserts every row carries one.
 *
 * Nullable because a widget may legitimately have no deeper page — the
 * self-service tiles that already resolve to a portal the user is standing on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->string('link_path', 120)->nullable()->after('render_kind');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn('link_path');
        });
    }
};
