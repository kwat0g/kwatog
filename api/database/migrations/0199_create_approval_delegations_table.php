<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();
            // null role_slug = delegate covers EVERY role the delegator currently holds.
            $table->string('role_slug', 50)->nullable();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->text('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['delegate_user_id', 'is_active'], 'appr_deleg_delegate_active_idx');
            $table->index('delegator_user_id', 'appr_deleg_delegator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
    }
};
