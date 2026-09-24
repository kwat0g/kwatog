<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table): void {
            $table->dropUnique('goods_receipt_notes_receiver_idempotency_unique');
            $table->unique('idempotency_key', 'goods_receipt_notes_idempotency_unique');
        });

        Schema::table('material_issue_slips', function (Blueprint $table): void {
            $table->dropUnique('material_issue_slips_creator_idempotency_unique');
            $table->unique('idempotency_key', 'material_issue_slips_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('material_issue_slips', function (Blueprint $table): void {
            $table->dropUnique('material_issue_slips_idempotency_unique');
            $table->unique(['created_by', 'idempotency_key'], 'material_issue_slips_creator_idempotency_unique');
        });

        Schema::table('goods_receipt_notes', function (Blueprint $table): void {
            $table->dropUnique('goods_receipt_notes_idempotency_unique');
            $table->unique(['received_by', 'idempotency_key'], 'goods_receipt_notes_receiver_idempotency_unique');
        });
    }
};
