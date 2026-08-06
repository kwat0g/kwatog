<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['accounting.accounts.asset_cash_code', '1010', 'Asset Disposal Cash Account Code'],
        ['accounting.accounts.asset_accumulated_depreciation_code', '1410', 'Accumulated Depreciation Account Code'],
        ['accounting.accounts.asset_cost_code', '1400', 'Asset Cost Account Code'],
        ['accounting.accounts.asset_disposal_loss_code', '6120', 'Asset Disposal Loss Account Code'],
        ['accounting.accounts.asset_disposal_gain_code', '4030', 'Asset Disposal Gain Account Code'],
        ['accounting.accounts.depreciation_expense_code', '6080', 'Depreciation Expense Account Code'],
    ];
    public function up(): void { foreach (self::ROWS as [$key,$value,$label]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>'accounting','label'=>$label,'description'=>'Chart-of-accounts mapping used by fixed-asset accounting.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->whereIn('key', array_column(self::ROWS,0))->delete(); }
};
