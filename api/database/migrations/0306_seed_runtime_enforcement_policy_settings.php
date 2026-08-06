<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    private const ROWS = [
        ['purchasing.urgent_skip_limit', 0, 'Urgent Purchase Request Skip Limit'],
        ['budgeting.enforcement_mode', 'warn', 'Budget Enforcement Mode'],
        ['accounting.je_self_post_limit', 0, 'Journal Entry Self-Post Limit'],
        ['inventory.adjustment_approval_threshold', 0, 'Inventory Adjustment Approval Threshold'],
    ];
    public function up(): void { foreach (self::ROWS as [$key,$value,$label]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>'system','label'=>$label,'description'=>'Runtime enforcement policy.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->whereIn('key',array_column(self::ROWS,0))->delete(); }
};
