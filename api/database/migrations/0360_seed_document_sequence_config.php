<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $monthly = static fn (string $prefix): array => ['prefix' => $prefix, 'reset' => 'monthly', 'pad' => 4];
        $yearly = static fn (string $prefix): array => ['prefix' => $prefix, 'reset' => 'yearly', 'pad' => 4];
        $config = [
            'employee' => $yearly('OGM'), 'purchase_order' => $monthly('PO'), 'invoice' => $monthly('INV'),
            'journal_entry' => $monthly('JE'), 'work_order' => $monthly('WO'), 'ncr' => $monthly('NCR'),
            'grn' => $monthly('GRN'), 'mrb' => $monthly('MRB'), 'sales_order' => $monthly('SO'),
            'mrp_plan' => $monthly('MRP'), 'leave_request' => $monthly('LR'), 'inspection' => $monthly('QC'),
            'pr' => $monthly('PR'), 'delivery' => $monthly('DR'), 'bill' => $monthly('BILL'),
            'credit_note' => $monthly('CN'), 'official_receipt' => $monthly('OR'), 'bank_payment' => $monthly('BPAY'),
            'loan' => $monthly('LN'), 'cash_advance' => $monthly('CA'), 'complaint' => $monthly('CMP'),
            'shipment' => $monthly('SHP'), 'maintenance_wo' => $monthly('MWO'), 'asset' => $yearly('AST'),
            'clearance' => $monthly('CLR'), 'production_batch' => $monthly('BATCH'), 'shipment_lot' => $monthly('LOT'),
            'stock_count' => $monthly('SC'), 'ppap' => $monthly('PPAP'), 'quote_request' => $monthly('QR'),
            'lead' => $monthly('LEAD'), 'opportunity' => $monthly('OPP'), 'quote' => $monthly('QT'),
            'asset_transfer' => $monthly('AT'), 'job_posting' => $monthly('JP'), 'job_application' => $monthly('JA'),
        ];
        DB::table('settings')->insertOrIgnore([
            'key' => 'documents.sequence_config', 'value' => json_encode($config), 'group' => 'documents',
            'label' => 'Document Sequence Configuration',
            'description' => 'Prefixes, reset cadence, and padding for generated document numbers.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'documents.sequence_config')->delete();
    }
};
