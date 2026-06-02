<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->integer('year')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('active'); // draft / active / closed
            $table->timestamps();
        });

        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('budget_type', 30);           // department / project / capex / opex
            $table->string('name', 200);
            $table->decimal('total_allocated', 15, 2)->default(0);
            $table->decimal('total_spent', 15, 2)->default(0);
            $table->decimal('total_committed', 15, 2)->default(0); // approved POs not yet billed
            $table->string('status', 20)->default('draft'); // draft / submitted / approved / active / closed
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('jan', 15, 2)->default(0);
            $table->decimal('feb', 15, 2)->default(0);
            $table->decimal('mar', 15, 2)->default(0);
            $table->decimal('apr', 15, 2)->default(0);
            $table->decimal('may', 15, 2)->default(0);
            $table->decimal('jun', 15, 2)->default(0);
            $table->decimal('jul', 15, 2)->default(0);
            $table->decimal('aug', 15, 2)->default(0);
            $table->decimal('sep', 15, 2)->default(0);
            $table->decimal('oct', 15, 2)->default(0);
            $table->decimal('nov', 15, 2)->default(0);
            $table->decimal('dec', 15, 2)->default(0);
            $table->decimal('annual_total', 15, 2)->storedAs('jan+feb+mar+apr+may+jun+jul+aug+sep+oct+nov+dec');
            $table->decimal('actual_total', 15, 2)->default(0);
            $table->decimal('variance', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('budget_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_budget_line_id')->constrained('budget_line_items')->restrictOnDelete();
            $table->foreignId('to_budget_line_id')->constrained('budget_line_items')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending / approved / rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->integer('revision_number');
            $table->json('changes'); // [{line_item_id, old_amount, new_amount, reason}]
            $table->text('reason');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending / approved / rejected
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_revisions');
        Schema::dropIfExists('budget_transfers');
        Schema::dropIfExists('budget_line_items');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('fiscal_years');
    }
};
