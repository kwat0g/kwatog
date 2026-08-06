<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.document.categories',
            'value' => json_encode([
                ['value' => 'sop', 'label' => 'SOP'], ['value' => 'work_instruction', 'label' => 'Work Instruction'],
                ['value' => 'form', 'label' => 'Form'], ['value' => 'policy', 'label' => 'Policy'],
                ['value' => 'specification', 'label' => 'Specification'],
            ]),
            'group' => 'quality', 'label' => 'Controlled Document Categories',
            'description' => 'Configurable categories available when creating controlled QMS documents.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.document.categories')->delete();
    }
};
