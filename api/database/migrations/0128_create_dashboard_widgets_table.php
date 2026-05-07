<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series R — Task R4.
 *
 * Catalog of dashboard widgets. Each widget has a stable string `key` that
 * the frontend registry maps to a React component. `permission` (nullable)
 * gates rendering server-side: layout endpoints strip widgets the user
 * cannot see. New widgets are added via a seeder, not a UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('module', 50);
            $table->string('permission', 100)->nullable();
            $table->unsignedTinyInteger('default_w')->default(12);
            $table->unsignedTinyInteger('default_h')->default(4);
            $table->timestamps();

            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
