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
        Schema::table('return_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_discrepancy_id')->nullable()->unique();
        });
        // Preserve any reports created by the earlier intake. Future reports
        // use the shared case service; the legacy rows remain audit evidence.
        DB::table('delivery_quantity_discrepancies')->orderBy('id')->each(function ($report): void {
            $delivery = DB::table('deliveries')->find($report->delivery_id);
            $order = $delivery ? DB::table('sales_orders')->find($delivery->sales_order_id) : null;
            if (! $order) {
                throw new RuntimeException('Cannot migrate a delivery discrepancy without its source order.');
            }
            $actor = $report->resolved_by ?: DB::table('users')->orderBy('id')->value('id');
            if (! $actor) {
                throw new RuntimeException('Cannot migrate a delivery discrepancy without an internal audit actor.');
            }
            $caseId = DB::table('return_cases')->insertGetId([
                'case_number' => 'CASE-LEGACY-'.$report->id, 'legacy_discrepancy_id' => $report->id,
                'type' => 'customer', 'status' => $report->status === 'rejected' ? 'rejected' : 'submitted',
                'customer_id' => $order->customer_id, 'delivery_id' => $delivery->id,
                'created_by' => $actor, 'customer_portal_user_id' => $report->reported_by,
                'preferred_resolution' => 'advice', 'description' => $report->rationale,
                'created_at' => $report->created_at, 'updated_at' => $report->updated_at,
            ]);
            foreach (json_decode($report->lines, true, 512, JSON_THROW_ON_ERROR) as $row) {
                $line = DB::table('delivery_items')->find($row['delivery_item_id']);
                if (! $line || (int) $line->delivery_id !== (int) $delivery->id) {
                    throw new RuntimeException('Cannot migrate a discrepancy line outside its source delivery.');
                }
                $soLine = DB::table('sales_order_items')->find($line->sales_order_item_id);
                $received = min((float) $line->quantity, max(0, (float) $row['received_quantity']));
                DB::table('return_case_lines')->insert([
                    'return_case_id' => $caseId, 'source_delivery_item_id' => $line->id,
                    'product_id' => $soLine?->product_id, 'description' => 'Imported delivery quantity report',
                    'unit' => 'pcs', 'expected_quantity' => $line->quantity, 'received_quantity' => $received,
                    'missing_quantity' => bcsub((string) $line->quantity, (string) $received, 3), 'defective_quantity' => '0',
                    'source_unit_price' => $line->unit_price, 'created_at' => $report->created_at, 'updated_at' => $report->updated_at,
                ]);
            }
            DB::table('return_case_events')->insert([
                'return_case_id' => $caseId, 'action' => 'imported', 'message' => $report->rationale,
                'actor_type' => 'customer', 'actor_name' => DB::table('customer_portal_users')->where('id', $report->reported_by)->value('name') ?: 'Customer',
                'customer_portal_user_id' => $report->reported_by, 'is_public' => true, 'created_at' => $report->created_at,
            ]);
            if ($report->resolution_reason) {
                DB::table('return_case_events')->insert([
                    'return_case_id' => $caseId, 'action' => 'reject', 'message' => $report->resolution_reason,
                    'actor_type' => 'internal', 'actor_name' => 'Ogami review', 'user_id' => $report->resolved_by,
                    'is_public' => true, 'created_at' => $report->resolved_at ?: $report->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Imported cases may already have settlement effects; preserve them.
        Schema::table('return_cases', fn (Blueprint $table) => $table->dropColumn('legacy_discrepancy_id'));
    }
};
