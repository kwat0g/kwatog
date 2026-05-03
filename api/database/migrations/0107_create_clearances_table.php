<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 71. Employee separation + clearance.
 *
 * `clearance_items` JSON shape:
 *   [
 *     {department:"Production",  item_key:"tools_returned",      status:"pending"|"cleared"|"blocked",
 *      signed_by:user_id|null,    signed_at:iso|null,             remarks:string|null},
 *     ...
 *   ]
 *
 * `final_pay_breakdown` JSON shape:
 *   {
 *     last_salary_pro_rated: "0.00",
 *     unused_convertible_leave_value: "0.00",
 *     pro_rated_13th_month: "0.00",
 *     less_loan_balance: "0.00",
 *     less_unreturned_property_value: "0.00",
 *     less_advance: "0.00",
 *     net: "0.00"
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clearances', function (Blueprint $table) {
            $table->id();
            $table->string('clearance_no', 32)->unique();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('separation_date');
            $table->string('separation_reason', 30); // resigned | terminated | retired | end_of_contract
            $table->json('clearance_items');
            $table->boolean('final_pay_computed')->default(false);
            $table->decimal('final_pay_amount', 15, 2)->nullable();
            $table->json('final_pay_breakdown')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending | in_progress | completed | finalized | cancelled
            $table->foreignId('initiated_by')->constrained('users');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_id');
            $table->index('separation_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearances');
    }
};
