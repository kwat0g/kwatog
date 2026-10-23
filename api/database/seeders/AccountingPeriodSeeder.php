<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountingPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        $created = DB::table('accounting_periods')->insertOrIgnore([
            'year' => $year,
            'month' => $month,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info($created === 1
            ? "Opened accounting period {$year}-{$month}."
            : "Accounting period {$year}-{$month} already exists; left unchanged.");
    }
}
