<?php

declare(strict_types=1);

return [
    'eligible_item_types' => ['raw_material'],
    'fixed_sample_size' => 3,
    'pending_grn_grace_minutes' => 15,
    'templates' => [
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
    ],
];
