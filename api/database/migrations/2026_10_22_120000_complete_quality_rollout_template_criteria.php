<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $criteria = [
            'resin' => [
                'Certificate of analysis verified' => 'Certificate matches the purchase order, item specification, and delivered supplier lot.',
                'Moisture content' => 'Moisture content is at or below 0.2% per the approved material specification.',
                'Color and contamination check' => 'Color matches the approved resin specification; no visible contamination or foreign material.',
                'Packaging and supplier-lot identification' => 'Packaging is intact and supplier-lot identification matches receiving documents.',
            ],
            'general' => [
                'Material identity and specification verified' => 'Material code, description, and revision match the approved purchase order and specification.',
                'Certificate or supplier documentation verified' => 'Required supplier certificate or documentation matches the item and delivered supplier lot.',
                'Visible damage or contamination check' => 'No visible damage, corrosion, contamination, or condition outside the approved specification.',
                'Packaging and supplier-lot identification' => 'Packaging is intact and supplier-lot identification is legible and traceable to receiving documents.',
            ],
        ];

        foreach ($criteria as $template => $rows) {
            $key = "quality.rollout.template.{$template}";
            $stored = DB::table('settings')->where('key', $key)->value('value');
            $parameters = is_string($stored) ? json_decode($stored, true) : $stored;
            if (! is_array($parameters)) {
                continue;
            }

            $changed = false;
            foreach ($parameters as &$parameter) {
                $name = (string) ($parameter['parameter_name'] ?? '');
                if (trim((string) ($parameter['acceptance_criterion'] ?? '')) === '' && isset($rows[$name])) {
                    $parameter['acceptance_criterion'] = $rows[$name];
                    $changed = true;
                }
            }
            unset($parameter);

            if ($changed) {
                DB::table('settings')->where('key', $key)->update([
                    'value' => json_encode($parameters, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                Cache::forget("settings:{$key}");
            }
        }
    }

    public function down(): void
    {
        // Keep acceptance criteria after rollback; removing active check instructions would break receiving QC.
    }
};
