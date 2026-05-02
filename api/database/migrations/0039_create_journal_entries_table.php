<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entries (front-loaded by Sprint 3 / Task 29 for payroll → GL posting.
 * Sprint 4 / Task 32 builds a richer JE creation/post UI on top of this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_entries')) return;

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 30)->unique();
            $table->date('date');
            $table->text('description')->nullable();
            $table->string('reference_type', 50)->nullable(); // e.g. 'payroll_period'
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('total_debit', 15, 2)->default(0);
            $table->decimal('total_credit', 15, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft | posted | reversed
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index('status');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
