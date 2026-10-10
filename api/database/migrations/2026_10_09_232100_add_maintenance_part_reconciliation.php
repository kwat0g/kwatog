<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->timestamp('parts_reconciled_at')->nullable();
            $table->foreignId('parts_reconciled_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parts_reconciled_by');
            $table->dropColumn('parts_reconciled_at');
        });
    }
};
