<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_issue_slips', function (Blueprint $table) {
            $table->id();
            $table->string('slip_number', 20)->unique();
            $table->unsignedBigInteger('work_order_id')->nullable(); // FK in Sprint 6
            $table->date('issued_date');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('issued'); // draft/issued/cancelled
            $table->decimal('total_value', 15, 2)->default(0);
            $table->text('reference_text')->nullable(); // Sprint-5 stub for free-text WO link
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('work_order_id');
            $table->index('issued_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issue_slips');
    }
};
