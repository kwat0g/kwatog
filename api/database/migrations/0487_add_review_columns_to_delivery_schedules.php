<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B — internal review of portal-submitted delivery schedules.
 *
 * Delivery schedules arrive from the customer and supplier portals as
 * write-only rows nobody internal ever saw. These columns let staff
 * acknowledge or reject a submission and let the portal surface the outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_schedules', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reject_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'reject_reason']);
        });
    }
};
