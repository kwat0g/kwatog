<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->boolean('finance_only')->default(false)->after('status');
            $table->text('finance_only_reason')->nullable()->after('finance_only');
            $table->foreignId('finance_only_approved_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->decimal('original_unit_price', 14, 2)->nullable()->after('unit_price');
            $table->foreignId('source_delivery_item_id')->nullable()->after('source_invoice_item_id')->constrained('delivery_items')->nullOnDelete();
            $table->string('lot_number', 120)->nullable()->after('source_grn_item_id');
            $table->string('serial_number', 120)->nullable()->after('lot_number');
        });
    }
    public function down(): void {
        Schema::table('return_request_items', function (Blueprint $table): void { $table->dropForeign(['source_delivery_item_id']); $table->dropColumn(['original_unit_price','source_delivery_item_id','lot_number','serial_number']); });
        Schema::table('return_requests', function (Blueprint $table): void { $table->dropForeign(['finance_only_approved_by']); $table->dropColumn(['finance_only','finance_only_reason','finance_only_approved_by']); });
    }
};
