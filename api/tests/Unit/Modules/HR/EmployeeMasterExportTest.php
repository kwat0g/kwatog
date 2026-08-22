<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\HR;

use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Exports\EmployeeMasterExport;
use App\Modules\HR\Models\Employee;
use Tests\TestCase;

class EmployeeMasterExportTest extends TestCase
{
    public function test_status_column_maps_backed_enum_to_human_label(): void
    {
        EmployeeMasterExport::registerColumns();

        $employee = new Employee;
        $employee->status = EmployeeStatus::OnLeave;

        $row = (new EmployeeMasterExport(['status']))->map($employee);

        $this->assertSame(['On leave'], $row);
    }
}
