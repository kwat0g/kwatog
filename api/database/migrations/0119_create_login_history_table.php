<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U2 — append-only login history. One row per login attempt (success or fail).
 * Used by Admin > Users > Detail "Last logins" panel and for compliance audit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('email_attempted', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            // success | failed_credentials | failed_locked | failed_inactive | failed_password_expired
            $table->string('status', 30);
            $table->string('reason', 100)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_history');
    }
};
