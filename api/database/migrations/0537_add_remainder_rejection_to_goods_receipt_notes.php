<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->timestamp('remainder_rejected_at')->nullable()->after('status');
            $table->unsignedBigInteger('remainder_rejected_by')->nullable()->after('remainder_rejected_at');
            $table->text('remainder_rejected_reason')->nullable()->after('remainder_rejected_by');

            $table->foreign('remainder_rejected_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropForeignIdFor('remainder_rejected_by');
            $table->dropColumn(['remainder_rejected_at', 'remainder_rejected_by', 'remainder_rejected_reason']);
        });
    }
};
