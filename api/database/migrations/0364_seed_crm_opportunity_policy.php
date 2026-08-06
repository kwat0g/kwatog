<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'crm.opportunity.initial_probability', 'value' => json_encode(10),
            'group' => 'crm', 'label' => 'Opportunity Initial Probability (%)',
            'description' => 'Probability assigned when a lead is converted into an opportunity.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'crm.opportunity.initial_probability')->delete();
    }
};
