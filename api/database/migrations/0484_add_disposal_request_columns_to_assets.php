<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AS-03 — disposal is now an approval-gated two-phase action. These columns
 * hold the PENDING disposal proposal (proceeds, date, reason, requester)
 * between request and final approval; the canonical disposal_* columns are
 * only written when the chain approves and the JE posts. Null request
 * columns ⟺ no live disposal request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->decimal('disposal_request_amount', 15, 2)->nullable()->after('disposal_reason');
            $table->date('disposal_request_date')->nullable()->after('disposal_request_amount');
            $table->text('disposal_request_reason')->nullable()->after('disposal_request_date');
            $table->foreignId('disposal_requested_by')->nullable()->constrained('users')->nullOnDelete()->after('disposal_request_reason');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('disposal_requested_by');
            $table->dropColumn(['disposal_request_amount', 'disposal_request_date', 'disposal_request_reason']);
        });
    }
};
