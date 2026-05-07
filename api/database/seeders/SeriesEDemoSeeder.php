<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Enums\DocumentType;
use App\Common\Enums\ExportFormat;
use App\Common\Enums\ExportFrequency;
use App\Common\Models\Document;
use App\Common\Models\ExportColumnPreference;
use App\Common\Models\ScheduledExport;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Services\PayslipPdfService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Series E (E1/E2/E3) — demo data so the document vault and scheduled-export
 * pages aren't empty out of the box.
 *
 *   php artisan db:seed --class=SeriesEDemoSeeder
 *
 * Idempotent: each section guards itself with a count check so re-runs
 * are no-ops once the demo set exists.
 *
 * What it produces:
 *   - Up to 5 real payslip PDFs in the vault, attached to the most recent
 *     finalized payrolls. Routed through the refactored PayslipPdfService
 *     so the vault stamps real bytes + SHA-256 + watermark.
 *   - Up to 3 invoice PDFs in the vault for the most recent finalized
 *     invoices, rendered through the existing pdf.invoice Blade so
 *     letterhead + footer all show.
 *   - 3 ScheduledExport rows for the admin user (one daily-active, one
 *     weekly-paused, one monthly-active), all targeting hr.employees.
 *   - 1 ExportColumnPreference row pinning the admin's preferred columns
 *     so the column selector remembers them.
 */
class SeriesEDemoSeeder extends Seeder
{
    public function run(
        DocumentVaultService $vault,
        PayslipPdfService $payslips,
        PdfRenderService $pdfRenderer,
    ): void {
        $admin = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))
            ->orderBy('id')
            ->first();

        if (! $admin) {
            $this->command?->warn('SeriesEDemoSeeder: no system_admin user found, skipping.');
            return;
        }

        $this->seedPayslipDocuments($admin, $payslips, $vault);
        $this->seedInvoiceDocuments($admin, $vault, $pdfRenderer);
        $this->seedScheduledExports($admin);
        $this->seedColumnPreferences($admin);
    }

    private function seedPayslipDocuments(User $admin, PayslipPdfService $payslips, DocumentVaultService $vault): void
    {
        if (Document::query()->where('document_type', DocumentType::Payslip->value)->exists()) {
            $this->command?->info('SeriesEDemoSeeder: payslip vault rows already present, skipping.');
            return;
        }

        // payrolls table doesn't have its own status — finalization lives on
        // payroll_periods. Pick the most recent rows from finalized periods,
        // and if there are none yet, fall back to whatever is computed.
        $rows = Payroll::query()
            ->with(['employee.department', 'employee.position', 'period', 'deductionDetails'])
            ->whereHas('period', fn ($q) => $q->where('status', 'finalized'))
            ->whereNotNull('computed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            $rows = Payroll::query()
                ->with(['employee.department', 'employee.position', 'period', 'deductionDetails'])
                ->whereNotNull('computed_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get();
        }

        if ($rows->isEmpty()) {
            $this->command?->info('SeriesEDemoSeeder: no computed payrolls to attach payslips to.');
            return;
        }

        foreach ($rows as $payroll) {
            try {
                $payslips->generateAndStore($payroll, $admin);
            } catch (Throwable $e) {
                $this->command?->warn(
                    sprintf('SeriesEDemoSeeder: payslip generation failed for payroll %d: %s', $payroll->id, $e->getMessage()),
                );
            }
        }
        $this->command?->info("SeriesEDemoSeeder: seeded {$rows->count()} payslip vault rows.");
    }

    private function seedInvoiceDocuments(User $admin, DocumentVaultService $vault, PdfRenderService $pdfRenderer): void
    {
        if (Document::query()->where('document_type', DocumentType::Invoice->value)->exists()) {
            $this->command?->info('SeriesEDemoSeeder: invoice vault rows already present, skipping.');
            return;
        }

        // Invoice::status is enum-cast to InvoiceStatus, but the DB column
        // stores the string value ("finalized", "partial", "paid"). whereIn
        // works against the underlying string values.
        $invoices = Invoice::query()
            ->with(['customer', 'items'])
            ->whereIn('status', ['finalized', 'partial', 'paid'])
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        if ($invoices->isEmpty()) {
            $this->command?->info('SeriesEDemoSeeder: no finalized invoices to attach PDFs to.');
            return;
        }

        $count = 0;
        foreach ($invoices as $invoice) {
            try {
                $bytes = $pdfRenderer->render(
                    'pdf.invoice',
                    [
                        'invoice'  => $invoice,
                        'customer' => $invoice->customer,
                        'items'    => $invoice->items,
                    ],
                    [
                        'paper'        => 'a4',
                        'orientation'  => 'portrait',
                        'confidential' => false,
                        'generator'    => $admin,
                        'title'        => 'Tax Invoice',
                    ],
                );
                $vault->store($bytes, DocumentType::Invoice, $this->modelOrSelf($invoice), $admin, false);
                $count++;
            } catch (Throwable $e) {
                $this->command?->warn(
                    sprintf('SeriesEDemoSeeder: invoice PDF render failed for invoice %d: %s', $invoice->id, $e->getMessage()),
                );
            }
        }

        $this->command?->info("SeriesEDemoSeeder: seeded {$count} invoice vault rows.");
    }

    private function seedScheduledExports(User $admin): void
    {
        if (ScheduledExport::query()->where('owner_id', $admin->id)->exists()) {
            $this->command?->info('SeriesEDemoSeeder: scheduled exports already present, skipping.');
            return;
        }

        $now = now();

        ScheduledExport::create([
            'owner_id'     => $admin->id,
            'name'         => 'Active employees — daily',
            'module'       => 'hr.employees',
            'columns'      => ['employee_no', 'full_name', 'department', 'position', 'date_hired', 'status'],
            'filters'      => ['status' => 'active'],
            'format'       => ExportFormat::Xlsx->value,
            'frequency'    => ExportFrequency::Daily->value,
            'day_of_week'  => null,
            'day_of_month' => null,
            'time_of_day'  => '06:00',
            'recipients'   => ['hr@ogami.test'],
            'next_run_at'  => ExportFrequency::Daily->nextRunFrom($now, null, null, '06:00'),
            'is_active'    => true,
        ]);

        ScheduledExport::create([
            'owner_id'     => $admin->id,
            'name'         => 'Headcount roster — weekly Monday morning',
            'module'       => 'hr.employees',
            'columns'      => ['employee_no', 'full_name', 'department', 'employment_type', 'pay_type', 'monthly_salary'],
            'filters'      => [],
            'format'       => ExportFormat::Xlsx->value,
            'frequency'    => ExportFrequency::Weekly->value,
            'day_of_week'  => 1, // Monday
            'day_of_month' => null,
            'time_of_day'  => '07:30',
            'recipients'   => ['hr@ogami.test', 'finance@ogami.test'],
            'next_run_at'  => ExportFrequency::Weekly->nextRunFrom($now, 1, null, '07:30'),
            'is_active'    => false,
        ]);

        ScheduledExport::create([
            'owner_id'     => $admin->id,
            'name'         => 'Monthly compensation snapshot',
            'module'       => 'hr.employees',
            'columns'      => ['employee_no', 'full_name', 'monthly_salary', 'daily_rate', 'pay_type'],
            'filters'      => ['status' => 'active'],
            'format'       => ExportFormat::Csv->value,
            'frequency'    => ExportFrequency::Monthly->value,
            'day_of_week'  => null,
            'day_of_month' => 1,
            'time_of_day'  => '03:00',
            'recipients'   => ['payroll@ogami.test'],
            'next_run_at'  => ExportFrequency::Monthly->nextRunFrom($now, null, 1, '03:00'),
            'is_active'    => true,
        ]);

        $this->command?->info('SeriesEDemoSeeder: seeded 3 scheduled exports for admin.');
    }

    private function seedColumnPreferences(User $admin): void
    {
        ExportColumnPreference::updateOrCreate(
            ['user_id' => $admin->id, 'module' => 'hr.employees'],
            ['columns' => ['employee_no', 'full_name', 'department', 'position', 'monthly_salary', 'date_hired']],
        );

        $this->command?->info('SeriesEDemoSeeder: pinned admin column preferences for hr.employees.');
    }

    /**
     * Some entities in older fixtures don't extend Model directly; defensively
     * coerce so DocumentVaultService::store() always receives an Eloquent Model.
     */
    private function modelOrSelf(mixed $entity): Model
    {
        if ($entity instanceof Model) return $entity;
        throw new \RuntimeException('Cannot attach a vault row to a non-Eloquent entity.');
    }
}
