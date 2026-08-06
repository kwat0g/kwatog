<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    private const ROWS = [
        ['quality.ppap_gate_enabled', false, 'PPAP Gate Enabled'],
        ['company.employee_email_domain', 'ogami.ph', 'Employee Account Email Domain'],
    ];
    public function up(): void { foreach (self::ROWS as [$key,$value,$label]) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>json_encode($value),'group'=>str_starts_with($key,'quality.')?'quality':'company','label'=>$label,'description'=>'Runtime module policy.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->whereIn('key',array_column(self::ROWS,0))->delete(); }
};
