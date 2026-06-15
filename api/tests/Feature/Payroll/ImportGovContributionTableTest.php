<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use App\Modules\Payroll\Services\GovernmentContributionTableImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportGovContributionTableTest extends TestCase
{
    use RefreshDatabase;

    private function makeCsv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gov_csv_');
        file_put_contents($path, $body);
        return $path;
    }

    public function test_imports_new_brackets_and_deactivates_prior(): void
    {
        // Existing prior row for SSS — should be deactivated by import.
        GovernmentContributionTable::create([
            'agency'         => 'sss',
            'bracket_min'    => 0,
            'bracket_max'    => 4250,
            'ee_amount'      => 135.00,
            'er_amount'      => 285.00,
            'effective_date' => '2025-01-01',
            'is_active'      => true,
        ]);

        $csv = $this->makeCsv(<<<CSV
bracket_min,bracket_max,ee_amount,er_amount,effective_date
0,4250,180.00,380.00,2026-01-01
4250,4750,202.50,427.50,2026-01-01
CSV);

        $r = app(GovernmentContributionTableImportService::class)
            ->importFromPath(ContributionAgency::Sss, $csv);

        $this->assertSame(2, $r['total']);
        $this->assertSame(2, $r['imported']);
        $this->assertSame(0, $r['updated']);
        $this->assertSame(0, $r['skipped']);
        $this->assertSame(1, $r['deactivated_prior']);

        // 2026-01-01 rows active.
        $this->assertSame(2, GovernmentContributionTable::where('agency', 'sss')
            ->where('effective_date', '2026-01-01')
            ->where('is_active', true)
            ->count());
        // 2025 row no longer active.
        $this->assertSame(0, GovernmentContributionTable::where('agency', 'sss')
            ->where('effective_date', '2025-01-01')
            ->where('is_active', true)
            ->count());

        unlink($csv);
    }

    public function test_re_running_same_csv_is_idempotent(): void
    {
        $csv = $this->makeCsv(<<<CSV
bracket_min,bracket_max,ee_amount,er_amount,effective_date
0,4250,180.00,380.00,2026-01-01
CSV);

        $svc = app(GovernmentContributionTableImportService::class);
        $first = $svc->importFromPath(ContributionAgency::Sss, $csv);
        $second = $svc->importFromPath(ContributionAgency::Sss, $csv);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, GovernmentContributionTable::where('agency', 'sss')->count());
        unlink($csv);
    }

    public function test_bad_row_lands_in_errors_without_aborting_batch(): void
    {
        $csv = $this->makeCsv(<<<CSV
bracket_min,bracket_max,ee_amount,er_amount,effective_date
0,4250,180.00,380.00,2026-01-01
9999,100,202.50,427.50,2026-01-01
4250,4750,202.50,427.50,2026-01-01
CSV);

        $r = app(GovernmentContributionTableImportService::class)
            ->importFromPath(ContributionAgency::Sss, $csv);

        $this->assertSame(3, $r['total']);
        $this->assertSame(2, $r['imported']);
        $this->assertSame(1, $r['skipped']);
        $this->assertCount(1, $r['errors']);
        unlink($csv);
    }

    public function test_missing_required_column_fails_fast(): void
    {
        $csv = $this->makeCsv(<<<CSV
bracket_min,bracket_max,ee_amount,er_amount
0,4250,180.00,380.00
CSV);

        $r = app(GovernmentContributionTableImportService::class)
            ->importFromPath(ContributionAgency::Sss, $csv);

        $this->assertSame(0, $r['total']);
        $this->assertSame(0, $r['imported']);
        $this->assertCount(1, $r['errors']);
        $this->assertStringContainsString('effective_date', $r['errors'][0]['message']);
        unlink($csv);
    }
}
