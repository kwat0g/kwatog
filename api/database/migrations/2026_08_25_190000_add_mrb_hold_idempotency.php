<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_review_records', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->after('notes');
            $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->unique(['held_by', 'idempotency_key'], 'mrb_held_by_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('material_review_records', function (Blueprint $table): void {
            $table->dropUnique('mrb_held_by_idempotency_key_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
