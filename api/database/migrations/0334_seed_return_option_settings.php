<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $options = [
            'returns.reason_codes' => [
                ['value' => 'defective', 'label' => 'Defective product'], ['value' => 'damaged', 'label' => 'Damaged in transit'],
                ['value' => 'wrong_item', 'label' => 'Wrong item shipped'], ['value' => 'excess', 'label' => 'Excess quantity'],
                ['value' => 'customer_change', 'label' => 'Customer changed mind'], ['value' => 'quality_issue', 'label' => 'Quality issue'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'returns.resolutions' => [
                ['value' => 'replace', 'label' => 'Replace'], ['value' => 'refund', 'label' => 'Refund'],
                ['value' => 'credit_note', 'label' => 'Credit Note'], ['value' => 'scrap', 'label' => 'Scrap'],
                ['value' => 'return_to_vendor', 'label' => 'Return to Vendor'],
            ],
            'returns.item_conditions' => [
                ['value' => 'new', 'label' => 'New'], ['value' => 'used', 'label' => 'Used'],
                ['value' => 'damaged', 'label' => 'Damaged'], ['value' => 'defective', 'label' => 'Defective'],
                ['value' => 'obsolete', 'label' => 'Obsolete'],
            ],
        ];
        foreach ($options as $key => $value) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'returns',
                'label' => ucwords(str_replace(['returns.', '_'], ['', ' '], $key)),
                'description' => 'Configurable return-management option catalog.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['returns.reason_codes', 'returns.resolutions', 'returns.item_conditions'])->delete();
    }
};
