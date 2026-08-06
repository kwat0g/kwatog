<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'hr.self_service.certificate_catalog',
            'value' => json_encode([
                ['key' => 'employment', 'label' => 'Certificate of Employment', 'note' => 'Generated instantly'],
                ['key' => 'sss', 'label' => 'Certificate of SSS Contributions', 'note' => 'current_year'],
                ['key' => 'philhealth', 'label' => 'Certificate of PhilHealth Contributions', 'note' => 'current_year'],
                ['key' => 'pagibig', 'label' => 'Certificate of Pag-IBIG Contributions', 'note' => 'current_year'],
                ['key' => 'bir_2316', 'label' => 'BIR 2316 (Compensation)', 'note' => 'prior_year'],
            ]),
            'group' => 'hr',
            'label' => 'Self-Service Certificate Catalog',
            'description' => 'Certificate types and labels shown in employee self-service documents.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hr.self_service.certificate_catalog')->delete();
    }
};
