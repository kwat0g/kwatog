<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('short_closed_at')->nullable()->after('status');
            $table->unsignedBigInteger('short_closed_by')->nullable()->after('short_closed_at');
            $table->text('short_close_reason')->nullable()->after('short_closed_by');

            $table->foreign('short_closed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeignIdFor('short_closed_by');
            $table->dropColumn(['short_closed_at', 'short_closed_by', 'short_close_reason']);
        });
    }
};
