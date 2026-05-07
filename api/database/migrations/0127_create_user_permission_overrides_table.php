<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series R — Task R2.
 *
 * Per-user permission overrides. Resolution order at runtime:
 *   role permissions → +grants → -revokes (expired rows ignored)
 *
 * One row per (user_id, permission_id). Re-granting/revoking the same
 * permission overwrites the prior row through an upsert in the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('type', 10); // 'grant' | 'revoke'
            $table->foreignId('granted_by')->constrained('users');
            $table->text('reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
            $table->index('expires_at');
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
