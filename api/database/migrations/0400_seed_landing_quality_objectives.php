<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.quality_policy.objectives',
            'value' => json_encode([
                ['title' => 'Customer Satisfaction', 'body' => 'Achieve and sustain a customer satisfaction score of ≥ 95% through on-time delivery and zero field complaints.'],
                ['title' => 'Defect Prevention', 'body' => 'Maintain outgoing defect rate below 100 PPM through AQL 0.65 Level II sampling and 100% critical-dimension measurement.'],
                ['title' => 'On-Time Delivery', 'body' => 'Achieve ≥ 98% on-time-in-full (OTIF) delivery to all automotive OEM schedules.'],
                ['title' => 'Continual Improvement', 'body' => 'Close 100% of Non-Conformance Reports (NCRs) with verified corrective action within 30 days of issuance.'],
                ['title' => 'Employee Competence', 'body' => 'Ensure all production and QC personnel complete role-specific training annually, with competency re-verified every two years.'],
                ['title' => 'Supplier Quality', 'body' => 'Maintain incoming material rejection rate below 0.5% through incoming inspection and supplier performance monitoring.'],
                ['title' => 'Regulatory Compliance', 'body' => 'Achieve zero violations of applicable DOLE, DENR, and BFAD/FDA regulations within each calendar year.'],
            ]),
            'group' => 'landing', 'label' => 'Landing Quality Objectives',
            'description' => 'Measurable quality objectives printed in the public quality policy.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.quality_policy.objectives')->delete();
    }
};
