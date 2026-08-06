<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $templates = [
            'resin' => [
                ['parameter_name' => 'Certificate of analysis verified', 'parameter_type' => 'visual', 'is_critical' => true],
                ['parameter_name' => 'Moisture content', 'parameter_type' => 'dimensional', 'unit_of_measure' => '%', 'tolerance_min' => 0, 'tolerance_max' => 0.2, 'is_critical' => true],
                ['parameter_name' => 'Color and contamination check', 'parameter_type' => 'visual', 'is_critical' => true],
                ['parameter_name' => 'Packaging and supplier-lot identification', 'parameter_type' => 'visual', 'is_critical' => false],
            ],
            'general' => [
                ['parameter_name' => 'Material identity and specification verified', 'parameter_type' => 'visual', 'is_critical' => true],
                ['parameter_name' => 'Certificate or supplier documentation verified', 'parameter_type' => 'visual', 'is_critical' => true],
                ['parameter_name' => 'Visible damage or contamination check', 'parameter_type' => 'visual', 'is_critical' => true],
                ['parameter_name' => 'Packaging and supplier-lot identification', 'parameter_type' => 'visual', 'is_critical' => false],
            ],
        ];
        foreach ($templates as $name => $template) {
            DB::table('settings')->insertOrIgnore([
                'key' => 'quality.rollout.template.'.$name, 'value' => json_encode($template),
                'group' => 'quality', 'label' => ucfirst($name).' Quality Plan Template',
                'description' => 'Parameters used by the baseline quality-plan rollout command.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['quality.rollout.template.resin', 'quality.rollout.template.general'])->delete();
    }
};
