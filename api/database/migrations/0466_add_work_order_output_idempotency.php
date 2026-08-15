<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable production-output idempotency. Cache is only a fast replay path;
 * the output row and scoped unique key are the authoritative guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->after('remarks');
            $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->unique(
                ['work_order_id', 'idempotency_key'],
                'work_order_outputs_work_order_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->dropUnique('work_order_outputs_work_order_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
