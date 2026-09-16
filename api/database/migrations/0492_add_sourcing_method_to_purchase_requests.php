<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->string('sourcing_method', 20)->nullable()->after('po_conversion_status');
            $table->index('sourcing_method');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->dropIndex(['sourcing_method']);
            $table->dropColumn('sourcing_method');
        });
    }
};
