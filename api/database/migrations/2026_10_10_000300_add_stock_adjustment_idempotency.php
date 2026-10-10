<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->string('idempotency_fingerprint', 64)->nullable();
            $table->unique(['requested_by', 'idempotency_key'], 'stock_adjustments_requester_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->dropUnique('stock_adjustments_requester_key_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
