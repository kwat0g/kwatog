<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-13 — first-class credit notes (AR customer + AP supplier).
 *
 * Replaces the RMA "negative invoice" hack (which had no GL entry and polluted
 * aging) with a real instrument that posts a VAT-reversing journal entry and
 * can be applied/offset against open invoices or bills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_number', 40)->nullable()->unique(); // set on finalize
            $table->string('type', 12); // customer | supplier
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors');
            // The source document being credited (optional — a standalone credit is allowed).
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('bill_id')->nullable()->constrained('bills');
            $table->foreignId('return_request_id')->nullable(); // link back to an RMA
            $table->string('status', 12)->default('draft'); // draft|finalized|applied|void
            $table->date('date');
            $table->boolean('is_vatable')->default(true);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('applied_amount', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0); // unapplied credit remaining
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('customer_id');
            $table->index('vendor_id');
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            // Revenue account (customer credit) or expense account (supplier credit).
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('description', 200);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::create('credit_note_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('bill_id')->nullable()->constrained('bills');
            $table->decimal('amount', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_applications');
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
    }
};
