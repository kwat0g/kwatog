<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('portal_shipping_documents', fn (Blueprint $table) => $table->foreignId('supplier_shipment_id')
            ->nullable()->constrained('supplier_shipments')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('portal_shipping_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_shipment_id'));
    }
};
