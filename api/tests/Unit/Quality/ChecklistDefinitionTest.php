<?php

declare(strict_types=1);

namespace Tests\Unit\Quality;

use App\Modules\Quality\Support\ChecklistDefinition;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChecklistDefinitionTest extends TestCase
{
    public function test_duplicate_names_with_different_spacing_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        ChecklistDefinition::validate([
            ['parameter_name' => ' Surface   condition ', 'acceptance_criterion' => 'No cracks.'],
            ['parameter_name' => 'Surface condition', 'acceptance_criterion' => 'Approved sample.'],
        ]);
    }

    public function test_criterion_required_and_numeric_fields_forbidden(): void
    {
        $this->expectException(ValidationException::class);
        ChecklistDefinition::validate([
            ['parameter_name' => 'Fit', 'acceptance_criterion' => ' ', 'nominal_value' => 10],
        ]);
    }

    public function test_checklist_preserves_classification_without_requiring_numbers(): void
    {
        $checks = ChecklistDefinition::validate([
            ['parameter_name' => ' Fit ', 'acceptance_criterion' => 'Fits approved gauge.',
                'parameter_type' => 'dimensional', 'is_critical' => true],
            ['parameter_name' => 'Surface', 'acceptance_criterion' => 'No cracks.'],
        ]);
        $this->assertSame('Fit', $checks[0]['parameter_name']);
        $this->assertSame('dimensional', $checks[0]['parameter_type']);
        $this->assertNull($checks[1]['parameter_type']);
        $this->assertNull($checks[0]['nominal_value']);
    }
}
