<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ADV6 — New fields on purchase_requests
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->after('mrp_plan_id');
            $table->string('auto_generated_reason', 100)->nullable()->after('is_auto_generated');
            $table->boolean('is_urgent')->default(false)->after('auto_generated_reason');
            $table->string('urgency_reason', 200)->nullable()->after('is_urgent');
        });

        // ADV6 — suggested_vendor_id on PR items (for auto-populated preferred supplier)
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->unsignedBigInteger('suggested_vendor_id')->nullable()->after('purpose');
        });

        // ADV6 — PR template table for recurring purchases
        Schema::create('purchase_request_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->json('items'); // [{item_id, description, quantity, unit, estimated_unit_price}]
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_templates');

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropColumn('suggested_vendor_id');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['template_id', 'auto_generated_reason', 'is_urgent', 'urgency_reason']);
        });
    }
};
