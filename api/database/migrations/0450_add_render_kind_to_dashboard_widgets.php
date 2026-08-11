<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a widget draws itself.
 *
 * Every widget previously rendered as one scalar number, so a GROUP BY was
 * flattened into a helper string (DashboardWidgetDataService::breakdown) and a
 * Pareto, a trend and a count all looked identical. This column carries the
 * shape; `permission` still carries visibility. Presentation and access stay
 * separate concerns — a widget never becomes visible by changing how it draws.
 *
 * Defaults to 'scalar' so every existing row keeps its current rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->string('render_kind', 20)->default('scalar')->after('permission');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn('render_kind');
        });
    }
};
