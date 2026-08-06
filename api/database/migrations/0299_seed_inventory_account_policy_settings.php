<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['accounting.accounts.grni_code', '2110', 'GRNI Clearing Account Code'],
        ['accounting.accounts.inventory_raw_material_code', '1200', 'Raw Materials Inventory Account Code'],
        ['accounting.accounts.inventory_finished_goods_code', '1210', 'Finished Goods Inventory Account Code'],
        ['accounting.accounts.inventory_packaging_code', '1220', 'Packaging Inventory Account Code'],
        ['accounting.accounts.inventory_spare_parts_code', '1230', 'Spare Parts Inventory Account Code'],
    ];
    public function up(): void { foreach (self::ROWS as [$key,$value,$label]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>'accounting','label'=>$label,'description'=>'Chart-of-accounts mapping used by inventory receipts.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->whereIn('key', array_column(self::ROWS,0))->delete(); }
};
