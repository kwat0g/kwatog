<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { DB::table('settings')->insertOrIgnore(['key'=>'accounting.accounts.payroll_cash_code','value'=>json_encode('1010'),'group'=>'accounting','label'=>'Payroll Cash Account Code','description'=>'Chart-of-accounts mapping used for payroll net-pay disbursements.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->where('key','accounting.accounts.payroll_cash_code')->delete(); }
};
