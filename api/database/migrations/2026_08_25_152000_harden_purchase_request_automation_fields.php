<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Do not silently discard an automation reference while adding the
        // constraint. An invalid legacy row must be repaired before deploy.
        $orphanedTemplates = DB::table('purchase_requests as pr')
            ->whereNotNull('pr.template_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('purchase_request_templates as template')
                    ->whereColumn('template.id', 'pr.template_id');
            })
            ->exists();

        $orphanedVendors = DB::table('purchase_request_items as item')
            ->whereNotNull('item.suggested_vendor_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('vendors as vendor')
                    ->whereColumn('vendor.id', 'item.suggested_vendor_id');
            })
            ->exists();

        if ($orphanedTemplates || $orphanedVendors) {
            throw new RuntimeException(
                'Purchase-request automation references contain orphaned template or vendor IDs; repair them before migrating.',
            );
        }

        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->index('template_id', 'purchase_requests_template_id_index');
            $table->foreign('template_id', 'purchase_requests_template_id_foreign')
                ->references('id')
                ->on('purchase_request_templates')
                ->nullOnDelete();
        });

        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->index('suggested_vendor_id', 'purchase_request_items_suggested_vendor_id_index');
            $table->foreign('suggested_vendor_id', 'purchase_request_items_suggested_vendor_id_foreign')
                ->references('id')
                ->on('vendors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->dropForeign('purchase_request_items_suggested_vendor_id_foreign');
            $table->dropIndex('purchase_request_items_suggested_vendor_id_index');
        });

        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->dropForeign('purchase_requests_template_id_foreign');
            $table->dropIndex('purchase_requests_template_id_index');
        });
    }
};
