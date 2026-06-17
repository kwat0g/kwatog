<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-018 — self-service password reset tokens.
 *
 * Distinct from Laravel's default `password_reset_tokens` broker table: this is
 * keyed by an opaque, single-use token (stored hashed) so the SPA reset page —
 * which only carries `?token=` in the URL — can resolve the owning user without
 * also exposing the email in the link. One un-used, un-expired row per request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // sha256 of the raw token handed to the user — never store the raw value.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
