<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'VL',   'name' => 'Vacation Leave',          'default_balance' => 15.0, 'is_paid' => true, 'requires_document' => false, 'is_convertible_on_separation' => true,  'is_convertible_year_end' => false],
            ['code' => 'SL',   'name' => 'Sick Leave',              'default_balance' => 15.0, 'is_paid' => true, 'requires_document' => true,  'is_convertible_on_separation' => false, 'is_convertible_year_end' => false],
            ['code' => 'SIL',  'name' => 'Service Incentive Leave', 'default_balance' => 5.0,  'is_paid' => true, 'requires_document' => false, 'is_convertible_on_separation' => true,  'is_convertible_year_end' => true],
            ['code' => 'ML',   'name' => 'Maternity Leave',         'default_balance' => 105.0,'is_paid' => true, 'requires_document' => true,  'is_convertible_on_separation' => false, 'is_convertible_year_end' => false],
            ['code' => 'PL',   'name' => 'Paternity Leave',         'default_balance' => 7.0,  'is_paid' => true, 'requires_document' => true,  'is_convertible_on_separation' => false, 'is_convertible_year_end' => false],
            ['code' => 'SPL',  'name' => 'Solo Parent Leave',       'default_balance' => 7.0,  'is_paid' => true, 'requires_document' => true,  'is_convertible_on_separation' => false, 'is_convertible_year_end' => false],
            ['code' => 'VAWC', 'name' => 'VAWC Leave',              'default_balance' => 10.0, 'is_paid' => true, 'requires_document' => true,  'is_convertible_on_separation' => false, 'is_convertible_year_end' => false],
            ['code' => 'SLW',  'name' => 'Special Leave for Women', 'default_balance' => 60.0, 'is_paid' => true, 'requires_document' => true,  'is_convertible_on_separation' => false, 'is_convertible_year_end' => false],
        ];

        foreach ($types as $t) {
            LeaveType::updateOrCreate(['code' => $t['code']], $t + ['conversion_rate' => 1.00, 'is_active' => true]);
        }
        $this->command?->info('Leave types seeded.');
    }
}
