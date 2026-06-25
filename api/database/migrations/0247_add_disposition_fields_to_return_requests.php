<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_request_items', function (Blueprint $table) {
            $table->string('disposition', 30)->nullable()->after('condition');
            $table->text('disposition_notes')->nullable()->after('disposition');
            $table->foreignId('ncr_id')->nullable()->constrained('non_conformance_reports')->after('disposition_notes');
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('credit_memo_id')->nullable()->constrained('invoices')->after('inspection_id');
            $table->string('disposition_status', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('return_request_items', function (Blueprint $table) {
            $table->dropForeign(['ncr_id']);
            $table->dropColumn(['disposition', 'disposition_notes', 'ncr_id']);
        });
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropForeign(['credit_memo_id']);
            $table->dropColumn(['credit_memo_id', 'disposition_status']);
        });
    }
};
