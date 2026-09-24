<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->foreignId('voided_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('void_reversal_journal_entry_id')->nullable()->after('voided_by')->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('void_reversal_journal_entry_id');
            $table->text('void_reason')->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropForeign(['voided_by']);
            $table->dropForeign(['void_reversal_journal_entry_id']);
            $table->dropColumn([
                'voided_by',
                'void_reversal_journal_entry_id',
                'voided_at',
                'void_reason',
            ]);
        });
    }
};
