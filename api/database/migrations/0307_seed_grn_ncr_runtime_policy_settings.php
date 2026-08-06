<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    private const ROWS = [
        ['inventory.over_receipt_tolerance_pct', 0, 'Inventory Over-Receipt Tolerance (%)'],
        ['quality.ncr.replacement_work_order_lead_days', 7, 'NCR Replacement Work Order Lead Days'],
        ['quality.ncr.replacement_work_order_priority', 5, 'NCR Replacement Work Order Priority'],
    ];
    public function up(): void { foreach (self::ROWS as [$key,$value,$label]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>'quality','label'=>$label,'description'=>'Quality and receiving runtime policy.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->whereIn('key',array_column(self::ROWS,0))->delete(); }
};
