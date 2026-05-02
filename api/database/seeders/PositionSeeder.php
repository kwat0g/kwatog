<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    /** @var array<string, array<int, string>> */
    private array $catalog = [
        'EXEC'  => ['Chairman', 'President', 'Vice President'],
        'HR'    => ['HR Manager', 'Gen Admin Officer', 'HR Staff'],
        'FIN'   => ['Accounting Officer', 'Accounting Staff'],
        'PROD'  => ['Plant Manager', 'Production Manager', 'Production Head', 'Processing Head', 'Production Operator'],
        'QC'    => ['QC/QA Manager', 'QC/QA Head', 'QC Inspector', 'Management System Head'],
        'WH'    => ['Warehouse Head', 'Warehouse Staff', 'Driver'],
        'PUR'   => ['Purchasing Officer', 'Purchasing Staff'],
        'PPC'   => ['PPC Head', 'PPC Staff'],
        'MAINT' => ['Maintenance Head', 'Maintenance Technician'],
        'MOLD'  => ['Mold Manager', 'Mold Technician'],
        'IMPEX' => ['ImpEx Officer', 'ImpEx Staff'],
        'ADMIN' => ['Admin Staff'],
    ];

    public function run(): void
    {
        foreach ($this->catalog as $code => $titles) {
            $dept = Department::where('code', $code)->first();
            if (!$dept) continue;

            foreach ($titles as $title) {
                Position::updateOrCreate(
                    ['title' => $title, 'department_id' => $dept->id],
                    [],
                );
            }
        }

        $this->command?->info('Positions seeded.');
    }
}
