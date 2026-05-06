<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WS-A.1 — Self-service portal account invites.
 *
 * Lets HR (or anyone with `auth.users.invite`) issue a one-time, expiring
 * link that an employee uses to set their portal password. The link is
 * pinned to one employee row so we cannot accidentally provision a user
 * for the wrong person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('email', 191);
            // 64-hex random — generated once, never updated.
            $table->string('token', 64)->unique();
            // Optional explicit role; if null, accept() uses position.default_role_id.
            $table->foreignId('role_id')->nullable()->constrained('roles');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            // Only one undismissed invite per employee at a time. Soft-deletes
            // (revocations) are excluded from uniqueness via the partial index
            // pattern used elsewhere in this codebase.
            $table->index(['employee_id', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invites');
    }
};
