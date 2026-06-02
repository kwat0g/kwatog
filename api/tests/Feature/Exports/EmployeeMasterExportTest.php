<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Common\Services\Export\ExportColumnRegistry;
use App\Modules\HR\Exports\EmployeeMasterExport;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeMasterExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Re-register to be defensive across tests that reset the registry.
        EmployeeMasterExport::registerColumns();
    }

    public function test_registers_with_canonical_module_key(): void
    {
        $this->assertTrue(ExportColumnRegistry::has(EmployeeMasterExport::MODULE));
        $this->assertContains('employee_no', ExportColumnRegistry::defaultsFor(EmployeeMasterExport::MODULE));
    }

    public function test_headings_match_selected_columns_in_order(): void
    {
        $export = new EmployeeMasterExport(['employee_no', 'full_name', 'department']);
        $this->assertSame(
            ['Employee No.', 'Name', 'Department'],
            $export->headings(),
        );
    }

    public function test_map_returns_resolved_values(): void
    {
        $dept = Department::create(['name' => 'Production', 'code' => 'PROD']);
        $pos = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        $emp = Employee::create([
            'employee_no'    => 'OGM-2026-0001',
            'first_name'     => 'Juan',
            'last_name'      => 'Cruz',
            'birth_date'     => '1990-01-01',
            'gender'         => 'male',
            'civil_status'   => 'single',
            'department_id'  => $dept->id,
            'position_id'    => $pos->id,
            'employment_type' => 'regular',
            'pay_type'       => 'monthly',
            'date_hired'     => '2026-01-10',
            'status'         => 'active',
        ]);

        $export = new EmployeeMasterExport(['employee_no', 'full_name', 'department']);
        $row = $export->map($emp->fresh(['department']));

        $this->assertSame('OGM-2026-0001', $row[0]);
        $this->assertStringContainsString('Cruz', (string) $row[1]);
        $this->assertSame('Production', $row[2]);
    }

    public function test_collection_filters_by_status(): void
    {
        $dept = Department::create(['name' => 'P', 'code' => 'P']);
        $pos = Position::create(['title' => 'X', 'department_id' => $dept->id]);

        Employee::create([
            'employee_no' => 'A', 'first_name' => 'A', 'last_name' => 'A',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => '2026-01-01', 'status' => 'active',
        ]);
        Employee::create([
            'employee_no' => 'B', 'first_name' => 'B', 'last_name' => 'B',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => '2026-01-01', 'status' => 'resigned',
        ]);

        $exp = new EmployeeMasterExport(['employee_no'], ['status' => 'active']);
        $rows = $exp->collection();
        $this->assertCount(1, $rows);
        $this->assertSame('A', $rows->first()->employee_no);
    }
}
