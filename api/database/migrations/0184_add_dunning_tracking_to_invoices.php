<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('last_dunning_tier')->default(0)->after('balance');
            $table->timestamp('last_dunning_at')->nullable()->after('last_dunning_tier');
            $table->index(['status', 'due_date', 'last_dunning_tier'], 'invoices_dunning_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_dunning_idx');
            $table->dropColumn(['last_dunning_tier', 'last_dunning_at']);
        });
    }
};
