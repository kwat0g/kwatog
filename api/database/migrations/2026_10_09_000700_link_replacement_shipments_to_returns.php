<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_shipments', function (Blueprint $table): void {
            $table->foreignId('return_request_id')->nullable()->after('purchase_order_id')
                ->constrained('return_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_request_id');
        });
    }
};
