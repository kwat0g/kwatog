<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/** The 18 standard PPAP elements (AIAG) + part submission warrant. */
enum PpapElementType: string
{
    case DesignRecord          = 'design_record';
    case EngineeringChange     = 'engineering_change';
    case CustomerApproval      = 'customer_approval';
    case Dfmea                 = 'dfmea';
    case ProcessFlow           = 'process_flow';
    case Pfmea                 = 'pfmea';
    case ControlPlan           = 'control_plan';
    case Msa                   = 'msa';
    case DimensionalResults    = 'dimensional_results';
    case MaterialTest          = 'material_test';
    case PerformanceTest       = 'performance_test';
    case InitialProcessStudy   = 'initial_process_study';
    case QualifiedLaboratory   = 'qualified_laboratory';
    case AppearanceApproval    = 'appearance_approval';
    case SampleProduct         = 'sample_product';
    case MasterSample          = 'master_sample';
    case CheckingAids          = 'checking_aids';
    case RecordsOfCompliance   = 'records_of_compliance';
    case PartSubmissionWarrant = 'part_submission_warrant';

    public function label(): string
    {
        return match ($this) {
            self::DesignRecord          => 'Design Record',
            self::EngineeringChange     => 'Engineering Change Documents',
            self::CustomerApproval      => 'Customer Engineering Approval',
            self::Dfmea                 => 'Design FMEA',
            self::ProcessFlow           => 'Process Flow Diagram',
            self::Pfmea                 => 'Process FMEA',
            self::ControlPlan           => 'Control Plan',
            self::Msa                   => 'Measurement System Analysis',
            self::DimensionalResults    => 'Dimensional Results',
            self::MaterialTest          => 'Material / Performance Test Results',
            self::PerformanceTest       => 'Performance Test Results',
            self::InitialProcessStudy   => 'Initial Process Studies',
            self::QualifiedLaboratory   => 'Qualified Laboratory Documentation',
            self::AppearanceApproval    => 'Appearance Approval Report',
            self::SampleProduct         => 'Sample Production Parts',
            self::MasterSample          => 'Master Sample',
            self::CheckingAids          => 'Checking Aids',
            self::RecordsOfCompliance   => 'Records of Compliance',
            self::PartSubmissionWarrant => 'Part Submission Warrant (PSW)',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
