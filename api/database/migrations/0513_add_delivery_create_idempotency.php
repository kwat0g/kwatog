<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->after('created_by');
            $table->char('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->unique(
                ['created_by', 'idempotency_key'],
                'deliveries_creator_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropUnique('deliveries_creator_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
