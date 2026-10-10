<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AuditBudgetTransferStartingStateSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') || ! filter_var(env('AUDIT_E2E_TESTING', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Audit-only budget starting data requires AUDIT_E2E_TESTING=true outside production.');
        }
        if (FiscalYear::query()->where('year', 2026)->exists()) {
            throw new RuntimeException('FY 2026 already exists; reset the isolated E2E database before reseeding.');
        }

        DB::transaction(static function (): void {
            FiscalYear::query()->create([
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'active',
            ]);
        });
    }
}
