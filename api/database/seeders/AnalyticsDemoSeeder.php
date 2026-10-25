<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Support\ProductionAssertions;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\PriceRequestStatus;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\Product;
use App\Modules\Dashboard\Services\KpiSnapshotService;
use App\Modules\DSS\Services\DssRunnerService;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;
use App\Modules\Purchasing\Services\SupplierPerformanceService;
use App\Modules\SupplyChain\Enums\DeliveryCostingMode;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Builds analytics outputs from demo transactions after source-data seeders finish. */
class AnalyticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        ProductionAssertions::assertDemoSeedingAllowed();

        if (! config('app.seed_demo_data')) {
            throw new RuntimeException('Analytics demo data requires SEED_DEMO_DATA=true.');
        }

        $this->seedWidgetScenarios();
        app(DemoInspectionSpecSeeder::class)->run();
        $this->seedOperationalHistory();
        $this->seedSupplierHistory();
        $this->computeKpiHistory();
        $this->runDetectors();
    }

    private function seedWidgetScenarios(): void
    {
        $customerId = DB::table('customers')->where('is_active', true)->orderBy('id')->value('id');
        $product = Product::query()->where('is_active', true)->orderBy('id')->first();
        if ($customerId && $product && ! PriceRequest::query()
            ->where('customer_id', $customerId)
            ->where('product_id', $product->id)
            ->whereIn('status', [
                PriceRequestStatus::Requested->value,
                PriceRequestStatus::PendingFinance->value,
                PriceRequestStatus::Offered->value,
            ])->exists()) {
            PriceRequest::query()->create([
                'customer_id' => $customerId,
                'product_id' => $product->id,
                'quantity' => 500,
                'requested_price' => $product->standard_cost,
                'notes' => 'Demo analytics scenario — customer price request awaiting review.',
                'status' => PriceRequestStatus::Requested,
            ]);
        }

        $maintenance = DB::table('maintenance_work_orders')
            ->where('status', 'in_progress')
            ->orderBy('id')
            ->first(['maintainable_type', 'maintainable_id']);
        if ($maintenance) {
            [$table, $prefix, $category] = match ($maintenance->maintainable_type) {
                'machine' => ['machines', 'AST-MCH-', 'machine'],
                'mold' => ['molds', 'AST-MLD-', 'mold'],
                default => [null, null, null],
            };
            if ($table) {
                $assetId = DB::table($table)->where('id', $maintenance->maintainable_id)->value('asset_id');
                $assetId ??= DB::table('assets')
                    ->where('asset_code', $prefix.sprintf('%04d', $maintenance->maintainable_id))
                    ->where('category', $category)
                    ->whereNull('deleted_at')
                    ->value('id');
                if ($assetId) {
                    DB::table($table)->where('id', $maintenance->maintainable_id)->whereNull('asset_id')
                        ->update(['asset_id' => $assetId]);
                    DB::table('assets')->where('id', $assetId)->whereNull('deleted_at')
                        ->update(['status' => AssetStatus::UnderMaintenance->value]);
                }
            }
        }

    }

    private function seedOperationalHistory(): void
    {
        $orders = DB::table('sales_orders')
            ->where('notes', 'like', 'Demo seed%')
            ->orderBy('id')
            ->limit(12)
            ->get();
        if ($orders->count() < 12) {
            $this->command?->warn('[Analytics] Twelve seeded sales orders are required for full operational history.');

            return;
        }

        $admin = User::query()->where('email', 'admin@ogami.test')->first();
        if (! $admin) {
            throw new RuntimeException('Analytics history requires admin@ogami.test.');
        }

        $firstMonth = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(10);
        foreach ($orders->take(10)->values() as $index => $order) {
            $line = DB::table('sales_order_items')
                ->where('sales_order_id', $order->id)
                ->orderBy('id')
                ->first();
            $workOrder = $line
                ? DB::table('work_orders')->where('sales_order_item_id', $line->id)->first()
                : null;
            $workOrder ??= DB::table('work_orders')
                ->where('sales_order_id', $order->id)
                ->orderBy('id')
                ->first();
            if (! $line || ! $workOrder) {
                throw new RuntimeException("Analytics history needs queued MRP work for sales order {$order->id}.");
            }

            $date = $firstMonth->addMonthsNoOverflow($index)->addDays(18)->toDateString();
            $startedAt = CarbonImmutable::parse($date)->setTime(8, 0);
            $finishedAt = $startedAt->addHours(8);
            DB::table('work_orders')->where('id', $workOrder->id)->update([
                'status' => 'in_progress',
                'planned_start' => $startedAt->toDateTimeString(),
                'planned_end' => $finishedAt->toDateTimeString(),
                'actual_start' => $startedAt->toDateTimeString(),
                'actual_end' => null,
                'updated_at' => now(),
            ]);

            $this->seedMaterialIssue((int) $workOrder->id, $admin, $date);
            $inspectionId = $this->seedOutgoingInspection($workOrder, $admin, $startedAt, $finishedAt);

            $target = (int) $workOrder->quantity_target;
            DB::table('work_orders')->where('id', $workOrder->id)->update([
                'status' => 'completed',
                'actual_end' => $finishedAt->toDateTimeString(),
                'quantity_produced' => $target,
                'quantity_good' => max(1, $target - 1),
                'quantity_rejected' => $target > 1 ? 1 : 0,
                'batch_number' => $workOrder->batch_number ?: 'ANALYTICS-BATCH-'.sprintf('%06d', $workOrder->id),
                'updated_at' => now(),
            ]);

            $deliveredAt = $finishedAt->addDay()->startOfDay();
            $this->seedDeliveredOrder($order, $line, $inspectionId, $deliveredAt->toDateString(), $deliveredAt, $admin);
        }

        $this->seedOverdueProductionDelivery($orders->get(10), $admin);
        $this->seedUpcomingProductionDelivery($orders->get(11), $admin);
        app(DemoInspectionEvidenceSeeder::class)->run();
        $this->command?->info('[Analytics] Seeded ten linked SO → WO → material issue → outgoing QC → delivery → invoice histories.');
    }

    private function seedMaterialIssue(int $workOrderId, User $admin, string $date): void
    {
        $slipId = DB::table('material_issue_slips')
            ->where('idempotency_key', 'analytics-material-'.$workOrderId)
            ->value('id');

        if (! $slipId) {
            $material = DB::table('work_order_materials')
                ->where('work_order_id', $workOrderId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->first(['item_id']);
            if (! $material) {
                throw new RuntimeException("Work order {$workOrderId} has no material plan for analytics history.");
            }

            $service = app(MaterialIssueService::class);
            $options = $service->options($workOrderId, (int) $material->item_id);
            $source = collect($options['sources'])->first(fn (array $row): bool => (float) $row['available'] > 0);
            $reservation = null;
            if (! $source) {
                $reservation = collect($options['reservations'])->first();
                $source = $reservation
                    ? ['location_id' => $reservation['location_id'], 'available' => $reservation['quantity']]
                    : null;
            }
            if (! $source) {
                throw new RuntimeException("No issuable source stock for work order {$workOrderId} analytics history.");
            }

            $quantity = min(1.0, (float) $source['available']);
            $line = [
                'item_id' => (int) $material->item_id,
                'location_id' => $source['location_id'],
                'quantity_issued' => number_format($quantity, 3, '.', ''),
            ];
            if ($reservation) {
                $line['material_reservation_id'] = $reservation['id'];
            }
            $dispatcher = Bus::getFacadeRoot();
            Bus::fake([RunAutomaticMrpJob::class]);
            try {
                // Historical orders complete below; an interim stock replan would duplicate WOs.
                $slip = $service->create([
                    'work_order_id' => $workOrderId,
                    'issued_date' => $date,
                    'items' => [$line],
                    'remarks' => 'Demo analytics history — work order material issue.',
                    'idempotency_key' => 'analytics-material-'.$workOrderId,
                ], $admin);
            } finally {
                Bus::swap($dispatcher);
            }
            $slipId = $slip->id;
        }

        DB::table('material_issue_slips')->where('id', $slipId)->update([
            'issued_date' => $date,
            'updated_at' => now(),
        ]);
        DB::table('stock_movements')
            ->where('reference_type', 'material_issue_slip')
            ->where('reference_id', $slipId)
            ->update(['created_at' => CarbonImmutable::parse($date)->setTime(8, 30)->toDateTimeString()]);
    }

    private function seedOutgoingInspection(object $workOrder, User $admin, CarbonImmutable $startedAt, CarbonImmutable $finishedAt): int
    {
        $spec = DB::table('inspection_specs as s')
            ->join('inspection_spec_revisions as r', function ($join): void {
                $join->on('r.inspection_spec_id', '=', 's.id')->on('r.version', '=', 's.version');
            })
            ->where('s.product_id', $workOrder->product_id)
            ->where('s.is_active', true)
            ->select(['s.id as inspection_spec_id', 'r.id as inspection_spec_revision_id'])
            ->first();
        if (! $spec) {
            throw new RuntimeException("Product {$workOrder->product_id} has no approved inspection spec for analytics history.");
        }

        $existing = DB::table('inspections')
            ->where('entity_type', 'work_order')
            ->where('entity_id', $workOrder->id)
            ->where('stage', 'outgoing')
            ->first();
        $sampleSize = min(10, max(1, (int) $workOrder->quantity_target));
        $values = [
            'stage' => 'outgoing',
            'status' => 'passed',
            'product_id' => $workOrder->product_id,
            'inspection_spec_id' => $spec->inspection_spec_id,
            'inspection_spec_revision_id' => $spec->inspection_spec_revision_id,
            'entity_type' => 'work_order',
            'entity_id' => $workOrder->id,
            'batch_quantity' => max(1, (int) $workOrder->quantity_target),
            'sample_size' => $sampleSize,
            'accept_count' => $sampleSize,
            'reject_count' => 0,
            'defect_count' => 0,
            'inspected_quantity' => $sampleSize,
            'sample_defect_count' => 0,
            'critical_defect' => false,
            'inspector_id' => $admin->id,
            'started_at' => $startedAt->toDateTimeString(),
            'completed_at' => $finishedAt->toDateTimeString(),
            'updated_at' => now(),
        ];

        if ($existing) {
            unset(
                $values['batch_quantity'], $values['sample_size'], $values['accept_count'],
                $values['reject_count'], $values['defect_count'], $values['inspected_quantity'],
                $values['sample_defect_count'], $values['critical_defect'],
            );
            DB::table('inspections')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        $template = DB::table('inspections')->where('stage', 'outgoing')->where('status', 'passed')->first();
        if (! $template) {
            throw new RuntimeException('A passed outgoing inspection template is required for analytics history.');
        }

        $values = [...(array) $template, ...$values];
        unset($values['id']);
        $values['inspection_number'] = 'AN-QC-'.sprintf('%06d', $workOrder->id);
        $values['created_at'] = $finishedAt->toDateTimeString();
        $values['work_order_output_id'] = null;
        $values['wo_operation_id'] = null;

        return (int) DB::table('inspections')->insertGetId($values);
    }

    private function seedDeliveredOrder(object $order, object $line, int $inspectionId, string $date, CarbonImmutable $deliveredAt, User $admin): void
    {
        $delivery = DB::table('deliveries')
            ->where('sales_order_id', $order->id)
            ->whereIn('status', ['delivered', 'confirmed'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
        if (! $delivery) {
            $number = 'AN-DEL-'.sprintf('%06d', $order->id);
            $deliveryId = DB::table('deliveries')->where('delivery_number', $number)->value('id');
            $values = [
                'delivery_number' => $number,
                'sales_order_id' => $order->id,
                'status' => DeliveryStatus::Delivered->value,
                'cost_recognition_mode' => DeliveryCostingMode::Legacy->value,
                'scheduled_date' => $date,
                'delivered_at' => $deliveredAt->toDateTimeString(),
                'created_by' => $admin->id,
                'receiver_name' => 'Demo Receiving Clerk',
                'receiver_position' => 'Receiving Clerk',
                'received_at' => $deliveredAt->toDateTimeString(),
                'delivery_remarks' => 'Demo analytics history — signed customer receipt.',
                'created_at' => $deliveredAt->toDateTimeString(),
                'updated_at' => now(),
            ];
            if ($deliveryId) {
                unset($values['status'], $values['cost_recognition_mode']);
                DB::table('deliveries')->where('id', $deliveryId)->update($values);
            } else {
                $deliveryId = DB::table('deliveries')->insertGetId($values);
            }
        } else {
            $deliveryId = (int) $delivery->id;
            DB::table('deliveries')->where('id', $deliveryId)->update([
                'scheduled_date' => $date,
                'delivered_at' => $deliveredAt->toDateTimeString(),
                'receiver_name' => 'Demo Receiving Clerk',
                'receiver_position' => 'Receiving Clerk',
                'received_at' => $deliveredAt->toDateTimeString(),
                'delivery_remarks' => 'Demo analytics history — signed customer receipt.',
                'updated_at' => now(),
            ]);
        }

        $deliveryItem = DB::table('delivery_items')
            ->where('delivery_id', $deliveryId)
            ->where('sales_order_item_id', $line->id)
            ->first();
        if (! $deliveryItem) {
            $oldIds = DB::table('delivery_items')->where('delivery_id', $deliveryId)->pluck('id');
            DB::table('invoice_items')->whereIn('source_delivery_item_id', $oldIds)->update(['source_delivery_item_id' => null]);
            DB::table('delivery_items')->where('delivery_id', $deliveryId)->delete();
            $deliveryItemId = DB::table('delivery_items')->insertGetId([
                'delivery_id' => $deliveryId,
                'sales_order_item_id' => $line->id,
                'inspection_id' => $inspectionId,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'created_at' => $deliveredAt->toDateTimeString(),
                'updated_at' => now(),
            ]);
        } else {
            $deliveryItemId = (int) $deliveryItem->id;
            DB::table('delivery_items')->where('id', $deliveryItemId)->update([
                'inspection_id' => $inspectionId,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'updated_at' => now(),
            ]);
        }

        DB::table('sales_order_items')->where('id', $line->id)->update([
            'quantity_delivered' => $line->quantity,
        ]);

        if (! DB::table('delivery_proofs')->where('delivery_id', $deliveryId)->whereNull('deleted_at')->exists()) {
            DB::table('delivery_proofs')->insert([
                'delivery_id' => $deliveryId,
                'proof_type' => 'signed_dr',
                'file_name' => 'demo-delivery-'.$deliveryId.'.pdf',
                'file_path' => 'demo/analytics/delivery-'.$deliveryId.'.pdf',
                'file_size' => 1024,
                'mime_type' => 'application/pdf',
                'uploaded_by' => $admin->id,
                'notes' => 'Demo analytics history — customer-signed delivery receipt.',
                'created_at' => $deliveredAt->toDateTimeString(),
                'updated_at' => now(),
            ]);
        }

        $deliveryModel = Delivery::query()->findOrFail($deliveryId);
        if ($deliveryModel->status === DeliveryStatus::Delivered) {
            app(DeliveryService::class)->confirm($deliveryModel, $admin, [
                'receiver_name' => 'Demo Receiving Clerk',
                'receiver_position' => 'Receiving Clerk',
                'delivery_remarks' => 'Demo analytics history — signed customer receipt.',
            ]);
        }
        DB::table('deliveries')->where('id', $deliveryId)->update([
            'confirmed_at' => $deliveredAt->toDateTimeString(),
            'updated_at' => now(),
        ]);

        $invoice = DB::table('invoices')->where('sales_order_id', $order->id)->orderBy('id')->first();
        if (! $invoice) {
            app(DeliveryService::class)->retryInvoiceHandoff(Delivery::query()->findOrFail($deliveryId), $admin);
            $invoice = DB::table('invoices')->where('delivery_id', $deliveryId)->orderBy('id')->first();
        }
        if (! $invoice) {
            throw new RuntimeException("Sales order {$order->id} did not produce an invoice after delivery confirmation.");
        }

        DB::table('invoices')->where('id', $invoice->id)->update(['delivery_id' => $deliveryId]);
        DB::table('deliveries')->where('id', $deliveryId)->update(['invoice_id' => $invoice->id]);
        $invoiceItemId = DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->value('id');
        if ($invoiceItemId) {
            DB::table('invoice_items')->where('id', $invoiceItemId)
                ->update(['source_delivery_item_id' => $deliveryItemId]);
        }

        DB::table('sales_orders')->where('id', $order->id)->update([
            'status' => match ($invoice->status) {
                'paid' => 'paid',
                'finalized', 'partial' => 'invoiced',
                default => 'delivered',
            },
            'delivered_at' => $deliveredAt->toDateTimeString(),
            'invoiced_at' => in_array($invoice->status, ['finalized', 'partial', 'paid'], true)
                ? $deliveredAt->toDateTimeString()
                : null,
            'paid_at' => $invoice->status === 'paid' ? $deliveredAt->toDateTimeString() : null,
            'updated_at' => now(),
        ]);
    }

    private function seedOverdueProductionDelivery(?object $order, User $admin): void
    {
        if (! $order) {
            throw new RuntimeException('Eleven confirmed demo orders are required for the overdue production scenario.');
        }

        if (! DB::table('work_orders')->where('sales_order_id', $order->id)->exists()) {
            throw new RuntimeException("Overdue sales order {$order->id} has no queued work order; drain MRP before analytics seeding.");
        }

        $number = 'AN-OVERDUE-'.sprintf('%06d', $order->id);
        $deliveryId = DB::table('deliveries')->where('delivery_number', $number)->value('id');
        $oldIds = $deliveryId ? DB::table('delivery_items')->where('delivery_id', $deliveryId)->pluck('id') : collect();
        DB::table('invoice_items')->whereIn('source_delivery_item_id', $oldIds)->update(['source_delivery_item_id' => null]);
        if ($deliveryId) {
            DB::table('delivery_items')->where('delivery_id', $deliveryId)->delete();
        }

        $date = now()->subDays(2)->toDateString();
        $values = [
            'sales_order_id' => $order->id,
            'status' => 'scheduled',
            'scheduled_date' => $date,
            'expected_delivery_date' => $date,
            'notes' => 'Demo analytics exception — production is still open; QC and delivery proof remain pending.',
            'updated_at' => now(),
        ];
        if ($deliveryId) {
            DB::table('deliveries')->where('id', $deliveryId)->update($values);
        } else {
            DB::table('deliveries')->insert([
                ...$values,
                'delivery_number' => $number,
                'created_by' => $admin->id,
                'created_at' => now(),
            ]);
        }
        DB::table('sales_order_items')->where('sales_order_id', $order->id)->update([
            'delivery_date' => $date,
            'requested_delivery_date' => $date,
        ]);
    }

    private function seedUpcomingProductionDelivery(?object $order, User $admin): void
    {
        if (! $order || ! DB::table('work_orders')->where('sales_order_id', $order->id)->exists()) {
            throw new RuntimeException('Twelve confirmed demo orders with queued MRP work are required for the delivery schedule scenario.');
        }

        $number = 'AN-SCHEDULED-'.sprintf('%06d', $order->id);
        $date = now()->addDays(5)->toDateString();
        DB::table('deliveries')->updateOrInsert(
            ['delivery_number' => $number],
            [
                'sales_order_id' => $order->id,
                'status' => 'scheduled',
                'scheduled_date' => $date,
                'expected_delivery_date' => $date,
                'notes' => 'Demo analytics plan — production and QC remain in progress.',
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('sales_order_items')->where('sales_order_id', $order->id)->update([
            'delivery_date' => $date,
            'requested_delivery_date' => $date,
        ]);
    }

    private function seedSupplierHistory(): void
    {
        $grn = DB::table('goods_receipt_notes as g')
            ->join('inspections as i', function ($join): void {
                $join->on('i.entity_id', '=', 'g.id')
                    ->where('i.entity_type', 'grn')
                    ->where('i.stage', 'incoming')
                    ->where('i.status', 'passed');
            })
            ->where('g.status', 'accepted')
            ->orderBy('g.id')
            ->select('g.*')
            ->first();
        if (! $grn || ! $grn->purchase_order_id) {
            $this->command?->warn('[Analytics] No accepted GRN with passed incoming QC; supplier KPI history skipped.');

            return;
        }

        $po = DB::table('purchase_orders')->where('id', $grn->purchase_order_id)->first();
        $poItems = DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->whereNull('deleted_at')->get();
        $grnItems = DB::table('grn_items')->where('goods_receipt_note_id', $grn->id)->get();
        if (! $po || $poItems->isEmpty() || $grnItems->isEmpty()) {
            $this->command?->warn('[Analytics] Accepted GRN lacks order-line evidence; supplier KPI history skipped.');

            return;
        }

        $sourceInspections = DB::table('inspections')
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->where('stage', 'incoming')
            ->where('status', 'passed')
            ->orderBy('id')
            ->get();
        $sourceMeasurements = DB::table('inspection_measurements')
            ->whereIn('inspection_id', $sourceInspections->pluck('id'))
            ->get()
            ->groupBy('inspection_id');
        if ($sourceInspections->isEmpty() || $sourceMeasurements->isEmpty()) {
            $this->command?->warn('[Analytics] Passed incoming QC source has no measurement evidence; supplier KPI history skipped.');

            return;
        }

        $month = CarbonImmutable::now()->startOfMonth();
        foreach (range(10, 1) as $monthsAgo) {
            $period = $month->subMonthsNoOverflow($monthsAgo);
            $poNumber = 'AN-PO-'.$period->format('Ym');
            $poId = DB::table('purchase_orders')->where('po_number', $poNumber)->value('id');
            $poItemIds = [];
            if (! $poId) {
                $poValues = (array) $po;
                unset($poValues['id']);
                $poValues = [...$poValues, 'po_number' => $poNumber, 'purchase_request_id' => null,
                    'request_for_quote_id' => null, 'date' => $period->addDays(3)->toDateString(),
                    'expected_delivery_date' => $period->addDays(20)->toDateString(), 'status' => 'received',
                    'remarks' => 'Demo analytics history — supplier receipt for monthly scorecard.',
                    'created_at' => $period->addDays(3)->toDateTimeString(), 'updated_at' => now()];
                $poId = DB::table('purchase_orders')->insertGetId($poValues);

                foreach ($poItems as $item) {
                    $values = (array) $item;
                    unset($values['id']);
                    $values['purchase_order_id'] = $poId;
                    $poItemIds[$item->id] = DB::table('purchase_order_items')->insertGetId($values);
                }

            } else {
                $targetPoItems = DB::table('purchase_order_items')
                    ->where('purchase_order_id', $poId)
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->get();
                if ($targetPoItems->count() !== $poItems->count()) {
                    throw new RuntimeException("Analytics PO {$poNumber} does not match its source order lines.");
                }
                foreach ($poItems->values() as $index => $item) {
                    $poItemIds[$item->id] = $targetPoItems[$index]->id;
                }
            }

            $grnNumber = 'AN-GRN-'.$period->format('Ym');
            $grnId = DB::table('goods_receipt_notes')->where('grn_number', $grnNumber)->value('id');
            if (! $grnId) {
                $grnValues = (array) $grn;
                unset($grnValues['id']);
                $grnValues = [...$grnValues, 'grn_number' => $grnNumber, 'purchase_order_id' => $poId,
                    'received_date' => $period->addDays(18)->toDateString(), 'status' => 'accepted',
                    'qc_inspection_id' => null, 'journal_entry_id' => null, 'shipment_id' => null,
                    'supplier_shipment_id' => null, 'idempotency_key' => null,
                    'idempotency_fingerprint' => null, 'idempotency_response' => null,
                    'created_at' => $period->addDays(18)->toDateTimeString(), 'updated_at' => now()];
                $grnId = DB::table('goods_receipt_notes')->insertGetId($grnValues);
            }

            $targetGrnItemIds = [];
            foreach ($grnItems as $item) {
                $poItemId = $poItemIds[$item->purchase_order_item_id] ?? null;
                if (! $poItemId) {
                    throw new RuntimeException("Analytics GRN {$grnNumber} has no matching purchase order line.");
                }
                $targetItemId = DB::table('grn_items')
                    ->where('goods_receipt_note_id', $grnId)
                    ->where('purchase_order_item_id', $poItemId)
                    ->value('id');
                if (! $targetItemId) {
                    $values = (array) $item;
                    unset($values['id']);
                    $values['goods_receipt_note_id'] = $grnId;
                    $values['purchase_order_item_id'] = $poItemId;
                    $values['material_lot_number'] = trim((string) $item->material_lot_number.'-'.$period->format('Ym'));
                    $values['supplier_lot_reference'] = trim((string) $item->supplier_lot_reference.'-'.$period->format('Ym'));
                    $values['created_at'] = $period->addDays(18)->toDateTimeString();
                    $values['updated_at'] = now();
                    $targetItemId = DB::table('grn_items')->insertGetId($values);
                }
                $targetGrnItemIds[$poItemId] = $targetItemId;
            }

            $hasPassedQc = DB::table('inspections')
                ->where('entity_type', 'grn')
                ->where('entity_id', $grnId)
                ->where('stage', 'incoming')
                ->where('status', 'passed')
                ->exists();
            if (! $hasPassedQc) {
                $firstInspectionId = null;
                foreach ($sourceInspections as $index => $inspection) {
                    $completedAt = $period->addDays(18)->setTime(10, 0)->toDateTimeString();
                    $sourceLine = $grnItems->firstWhere('id', $inspection->grn_item_id);
                    $poItemId = $sourceLine ? ($poItemIds[$sourceLine->purchase_order_item_id] ?? null) : null;
                    $inspectionValues = (array) $inspection;
                    unset($inspectionValues['id']);
                    $inspectionValues = [
                        ...$inspectionValues,
                        'inspection_number' => 'AN-QC-IN-'.$period->format('Ym').'-'.sprintf('%02d', $index + 1),
                        'entity_type' => 'grn',
                        'entity_id' => $grnId,
                        'grn_item_id' => $poItemId ? ($targetGrnItemIds[$poItemId] ?? null) : null,
                        'started_at' => $completedAt,
                        'completed_at' => $completedAt,
                        'notes' => "Demo analytics history — incoming QC copied from {$grn->grn_number}.",
                        'created_at' => $completedAt,
                        'updated_at' => now(),
                    ];
                    $inspectionId = DB::table('inspections')->insertGetId($inspectionValues);
                    $firstInspectionId ??= $inspectionId;

                    foreach ($sourceMeasurements->get($inspection->id, collect()) as $measurement) {
                        $measurementValues = (array) $measurement;
                        unset($measurementValues['id']);
                        $measurementValues['inspection_id'] = $inspectionId;
                        $measurementValues['created_at'] = $completedAt;
                        $measurementValues['updated_at'] = now();
                        DB::table('inspection_measurements')->insert($measurementValues);
                    }
                }
                DB::table('goods_receipt_notes')->where('id', $grnId)->update([
                    'qc_inspection_id' => $firstInspectionId,
                    'updated_at' => now(),
                ]);
            }

            app(SupplierPerformanceService::class)->compute(Vendor::findOrFail($grn->vendor_id), $period->year, $period->month);
        }

        $result = app(SupplierPerformanceService::class)->recomputeAll($month->year, $month->month);
        if ($result['failed'] !== []) {
            throw new RuntimeException('Current-month supplier performance calculation failed.');
        }
    }

    private function computeKpiHistory(): void
    {
        $service = app(KpiSnapshotService::class);
        $month = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(23);
        $computed = 0;
        $noData = 0;
        $failures = [];

        for ($offset = 0; $offset < 24; $offset++) {
            $result = $service->computeAll($month->year, $month->month);
            $computed += $result['computed'];
            $noData += $result['no_data'];
            $failures = [...$failures, ...$result['failed']];
            $month = $month->addMonthNoOverflow();
        }

        if ($failures !== []) {
            $codes = array_unique(array_column($failures, 'code'));
            throw new RuntimeException('KPI demo calculation failed for: '.implode(', ', $codes).'.');
        }

        $this->command?->info("[Analytics] Computed {$computed} monthly KPI snapshots; {$noData} periods had no source observations.");
    }

    private function runDetectors(): void
    {
        $result = app(DssRunnerService::class)->run();

        if ($result['failed'] !== []) {
            throw new RuntimeException('DSS demo detector run failed for: '.implode(', ', $result['failed']).'.');
        }

        if ($result['engine'] === 'disabled') {
            $this->command?->warn('[Analytics] DSS engine disabled; detector fixtures were not evaluated.');

            return;
        }

        $this->command?->info('[Analytics] Ran '.count($result['detectors']).' registered DSS detectors without changing detector modes.');
    }
}
