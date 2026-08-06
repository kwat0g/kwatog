<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.section_copy',
            'value' => json_encode([
                'capabilities_intro' => 'Every step of the value chain under one roof, under your specification.',
                'process_eyebrow' => 'The manufacturing process',
                'process_intro' => 'Quality is not a final gate — it is built into every stage. Here is how a part is made, checked, and released to you.',
                'quality_intro' => 'Quality is built into the chain. Four checkpoints stand between raw material and your receiving dock.',
                'part_showcase_intro' => 'Every molded part is a controlled geometry. Inspect a section before a single shot is run.',
            ]),
            'group' => 'landing',
            'label' => 'Landing Section Copy',
            'description' => 'Introductory copy used by public landing-page sections.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.section_copy')->delete();
    }
};
