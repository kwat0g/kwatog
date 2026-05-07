<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series R — Task R4.
 *
 * Polymorphic widget placement. `owner_type` is 'role' or 'user'.
 *   - role rows are seeded defaults (one set per role)
 *   - user rows are personal overrides (created on first login or by user)
 *
 * No FK on widget_key — widgets can be soft-removed from the catalog without
 * cascading away every layout row. The service filters unknown keys at read
 * time. Reset = delete all user rows for a given user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 10); // 'role' | 'user'
            $table->unsignedBigInteger('owner_id');
            $table->string('widget_key', 100);
            $table->unsignedSmallInteger('position_x')->default(0);
            $table->unsignedSmallInteger('position_y')->default(0);
            $table->unsignedTinyInteger('width')->default(12);
            $table->unsignedTinyInteger('height')->default(4);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index('widget_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
