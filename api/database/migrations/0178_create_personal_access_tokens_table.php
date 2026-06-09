<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-4 — Sanctum personal_access_tokens table for B2B portal Bearer tokens.
 *
 * The internal app uses Sanctum SPA cookie mode (no token storage), so the
 * default Sanctum migration was never published. The Supplier/Customer
 * portals issue Bearer tokens via $user->createToken() and need this table.
 *
 * Mirrors vendor/laravel/sanctum/database/migrations/2019_12_14_000001 so we
 * stay compatible if someone later runs `vendor:publish --tag=sanctum-migrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
