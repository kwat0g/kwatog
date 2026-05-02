<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('pr_number', 20)->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('date');
            $table->text('reason')->nullable();
            $table->string('priority', 10)->default('normal'); // normal/urgent/critical
            $table->string('status', 20)->default('draft'); // draft/pending/approved/rejected/converted/cancelled
            $table->boolean('is_auto_generated')->default(false);
            $table->unsignedTinyInteger('current_approval_step')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index('requested_by');
            $table->index('department_id');
            $table->index('is_auto_generated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
