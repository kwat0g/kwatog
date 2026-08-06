<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['accounting.accounts.final_pay_salary_expense_code', '6010', 'Final Pay Salary Expense Account Code'],
        ['accounting.accounts.cash_code', '1020', 'Cash Account Code'],
        ['accounting.accounts.loans_payable_code', '2100', 'Loans Payable Account Code'],
        ['accounting.accounts.accrued_expense_code', '2070', 'Accrued Expense Account Code'],
    ];
    public function up(): void { foreach (self::ROWS as [$key,$value,$label]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>'accounting','label'=>$label,'description'=>'Chart-of-accounts mapping used by final-pay journal posting.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->whereIn('key', array_column(self::ROWS,0))->delete(); }
};
