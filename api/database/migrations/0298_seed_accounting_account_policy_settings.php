<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['accounting.accounts.ar_code', '1100', 'Accounting AR Control Account Code'],
        ['accounting.accounts.ap_code', '2010', 'Accounting AP Control Account Code'],
        ['accounting.accounts.vat_output_code', '2060', 'Accounting VAT Output Account Code'],
        ['accounting.accounts.vat_input_code', '1310', 'Accounting VAT Input Account Code'],
        ['accounting.accounts.discount_code', '4010', 'Accounting Sales Discount Account Code'],
    ];
    public function up(): void {
        foreach (self::ROWS as [$key, $value, $label]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>'accounting','label'=>$label,'description'=>'Chart-of-accounts mapping used by accounting postings.','created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void { DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete(); }
};
