<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { DB::table('settings')->insertOrIgnore(['key'=>'accounting.functional_currency_code','value'=>json_encode('PHP'),'group'=>'accounting','label'=>'Functional Currency Code','description'=>'Currency code used by the general ledger and statements.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->where('key','accounting.functional_currency_code')->delete(); }
};
