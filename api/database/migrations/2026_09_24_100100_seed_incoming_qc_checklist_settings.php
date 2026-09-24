<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default incoming QC lot-checklist parameters and measured-piece count.
 *
 * quality.incoming.default_checklist: JSON array of visual checklist items
 *   (used when no quality plan exists).
 *
 * quality.incoming.measured_pieces: number of pieces to measure for
 *   dimensional/toleranced parameters when lot_checklist mode is active.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.incoming.default_checklist',
            'value' => json_encode([
                [
                    'parameter_name' => 'Delivery documents (DR/invoice) match the PO item and quantity',
                    'is_critical' => true,
                ],
                [
                    'parameter_name' => 'Certificate of Analysis / mill certificate received and matches the lot',
                    'is_critical' => true,
                ],
                [
                    'parameter_name' => 'Packaging sealed and undamaged (no wetness, contamination or tampering)',
                    'is_critical' => true,
                ],
                [
                    'parameter_name' => 'Labels show the correct item code, grade/colour and lot number',
                    'is_critical' => true,
                ],
                [
                    'parameter_name' => 'Condition on arrival acceptable (storage/handling)',
                    'is_critical' => false,
                ],
            ]),
            'group' => 'quality',
            'label' => 'Incoming QC Default Checklist',
            'description' => 'Default visual checklist items for incoming inspection when no quality plan is active.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.incoming.measured_pieces',
            'value' => json_encode(5),
            'group' => 'quality',
            'label' => 'Incoming QC Measured Pieces',
            'description' => 'Number of pieces to measure for dimensional/toleranced parameters in lot-checklist incoming inspections.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', ['quality.incoming.default_checklist', 'quality.incoming.measured_pieces'])
            ->delete();
    }
};
