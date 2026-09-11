<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery reschedule — a scheduled delivery's date was effectively immutable
 * after creation, so moving it (factory or customer cannot make the slot) had
 * no supported path and left no reason/history behind.
 *
 * `deliveries.original_scheduled_date` is written only on the first reschedule
 * so the original commitment survives every later move; `reschedule_count`
 * makes churn visible without aggregating the history table.
 *
 * `delivery_reschedules` is append-only history: one row per move with the
 * from/to dates, the operator's reason, and who made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->date('original_scheduled_date')->nullable();
            $table->unsignedInteger('reschedule_count')->default(0);
        });

        Schema::create('delivery_reschedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->date('from_date')->nullable();
            $table->date('to_date');
            $table->string('reason', 500);
            $table->foreignId('rescheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_reschedules');

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn(['original_scheduled_date', 'reschedule_count']);
        });
    }
};
