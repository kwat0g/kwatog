<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_password_history', function (Blueprint $table): void {
            $table->id();
            $table->string('portal_type', 32);
            $table->unsignedBigInteger('portal_user_id');
            $table->string('password_hash');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['portal_type', 'portal_user_id', 'created_at'], 'portal_password_history_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_password_history');
    }
};
