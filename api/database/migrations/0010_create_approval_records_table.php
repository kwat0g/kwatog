<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_records', function (Blueprint $table) {
            $table->id();
            $table->string('approvable_type', 100);
            $table->unsignedBigInteger('approvable_id');
            $table->unsignedTinyInteger('step_order');
            $table->string('role_slug', 50);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20)->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_records');
    }
};
