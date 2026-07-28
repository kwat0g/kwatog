<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_requests', 'purchase_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('budget_warning_level', 20)->nullable();
                $table->text('budget_warning_message')->nullable();
                $table->foreignId('budget_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('budget_acknowledged_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_orders', 'purchase_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('budget_acknowledged_by');
                $table->dropColumn(['budget_warning_level', 'budget_warning_message', 'budget_acknowledged_at']);
            });
        }
    }
};
