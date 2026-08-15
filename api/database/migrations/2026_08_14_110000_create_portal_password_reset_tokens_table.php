<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_password_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('portal_type', 20);
            $table->string('email', 255);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['portal_type', 'email']);
            $table->index(['portal_type', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_password_reset_tokens');
    }
};
