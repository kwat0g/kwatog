<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    private const ROWS = [
        ['accounting.accounts.sss_payable_code','2020'],['accounting.accounts.philhealth_payable_code','2030'],['accounting.accounts.pagibig_payable_code','2040'],['accounting.accounts.withholding_tax_payable_code','2050'],['accounting.accounts.thirteenth_month_payable_code','2080'],['accounting.accounts.salary_expense_code','5050'],['accounting.accounts.overtime_expense_code','5060'],['accounting.accounts.thirteenth_month_expense_code','5070'],['accounting.accounts.sss_employer_expense_code','6030'],['accounting.accounts.philhealth_employer_expense_code','6040'],['accounting.accounts.pagibig_employer_expense_code','6050'],
    ];
    public function up(): void { foreach (self::ROWS as [$key,$value]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>'accounting','label'=>ucwords(str_replace(['.','_'],' ',$key)),'description'=>'Chart-of-accounts mapping used by payroll GL posting.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->whereIn('key',array_column(self::ROWS,0))->delete(); }
};
