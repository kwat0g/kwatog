<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['approval.pr.dept_head_auto_approve_threshold', 5000, 'Purchase Request Dept-Head Auto-Approval Threshold'],
        ['accounting.default_expense_account_code', '5000', 'Default B2B Expense Account Code'],
    ];
    public function up(): void { foreach (self::ROWS as [$key,$value,$label]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>'purchasing','label'=>$label,'description'=>'Configurable purchasing and supplier-portal policy.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->whereIn('key', array_column(self::ROWS,0))->delete(); }
};
