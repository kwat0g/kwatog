<?php

declare(strict_types=1);

use App\Common\Support\ChainDefinitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'workflow.chain_definitions',
            'value' => json_encode(ChainDefinitions::defaults()),
            'group' => 'workflow',
            'label' => 'Workflow Chain Definitions',
            'description' => 'Ordered workflow steps and status mappings used by chain progress APIs.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'workflow.chain_definitions')->delete();
    }
};
