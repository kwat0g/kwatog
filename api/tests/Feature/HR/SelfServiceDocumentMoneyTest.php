<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Services\SettingsService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\SelfServiceDocumentService;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPublicationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** M024 statutory PDF totals must remain exact decimal strings at the boundary. */
class SelfServiceDocumentMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_contribution_certificate_sums_centavos_without_float_drift(): void
    {
        $employee = Employee::factory()->create();
        $this->payroll($employee, '0.10', '0.10', '0.10', '0.10', '0.10');
        $this->payroll($employee, '0.20', '0.20', '0.20', '0.20', '0.20');

        $rendered = null;
        $renderer = Mockery::mock(PdfRenderService::class);
        $renderer->shouldReceive('render')->once()->andReturnUsing(function (string $view, array $data) use (&$rendered): string {
            $rendered = $data;
            return 'pdf';
        });

        $service = new SelfServiceDocumentService(
            $renderer,
            Mockery::mock(SettingsService::class),
            new PayrollPublicationPolicy(),
        );

        $service->contributionCertificate($employee, 'sss', now()->year);

        self::assertIsArray($rendered);
        self::assertSame('0.30', $rendered['total']);
        self::assertSame(['0.10', '0.20'], array_column($rendered['rows'], 'amount'));
    }

    private function payroll(
        Employee $employee,
        string $gross,
        string $sss,
        string $philhealth,
        string $pagibig,
        string $tax,
    ): Payroll {
        $period = PayrollPeriod::factory()->create([
            'period_start' => now()->startOfYear()->toDateString(),
            'period_end' => now()->startOfYear()->addDays(14)->toDateString(),
        ]);
        $period->forceFill(['status' => 'finalized'])->saveQuietly();

        return Payroll::factory()->create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
            'gross_pay' => $gross,
            'sss_ee' => $sss,
            'philhealth_ee' => $philhealth,
            'pagibig_ee' => $pagibig,
            'withholding_tax' => $tax,
            'net_pay' => $gross,
        ]);
    }
}
