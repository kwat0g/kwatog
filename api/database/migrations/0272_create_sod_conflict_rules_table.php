<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-01 — data-driven Segregation-of-Duties conflict matrix. Each row declares
 * an incompatible pair of permissions: no single user should hold both, because
 * together they let one person both create and control the same transaction
 * (e.g. create a vendor AND approve a PO to it; post AND approve a JE).
 *
 * Replaces scattered one-off abort(403) guards with a matrix that can be read,
 * seeded, and reported on — the "who violates SoD today" audit artifact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sod_conflict_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();          // stable slug, e.g. 'po_create_vs_approve'
            $table->string('name', 150);
            $table->foreignId('permission_a_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('permission_b_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('severity', 10)->default('high'); // low | medium | high
            $table->text('rationale')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['permission_a_id', 'permission_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sod_conflict_rules');
    }
};
