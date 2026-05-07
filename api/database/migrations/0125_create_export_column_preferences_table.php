<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series E (Task E2) — per-user, per-module column-selection memory for
 * exports. Server-side so it survives device changes (localStorage drift
 * is the bug we're avoiding).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('export_column_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // 'hr.employees', 'payroll.register', etc. — matches the registry key.
            $table->string('module', 50);
            // Ordered array of column keys the user wants in their exports.
            $table->json('columns');
            $table->timestamps();

            $table->unique(['user_id', 'module'], 'export_col_prefs_user_module_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_column_preferences');
    }
};
