<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Enums\ExportFormat;
use App\Common\Enums\ExportFrequency;
use App\Common\Models\ScheduledExport;
use App\Common\Services\Export\ExportRunner;
use App\Common\Services\Export\SpreadsheetExportService;
use App\Common\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Series E (Task E2) — every-5-min scheduler tick. For each due export:
 *   1. Build the exporter via ExportRunner.
 *   2. Render to a temp file.
 *   3. Mail it to recipients.
 *   4. Update last_run_at + next_run_at.
 *
 * Mail body uses an inline anonymous Mailable so we don't need a Blade
 * file for every export type.
 */
class RunDueScheduledExports extends Command
{
    protected $signature = 'exports:run-due {--dry-run : List due rows without sending}';

    protected $description = 'Run all scheduled exports whose next_run_at has elapsed.';

    public function __construct(
        private readonly ExportRunner $runner,
        private readonly SpreadsheetExportService $spreadsheets,
        private readonly SettingsService $settings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $due = ScheduledExport::query()->due(now())->with('owner:id,name,email')->get();

        $this->info("Found {$due->count()} due export(s).");
        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($due as $row) {
            try {
                if ($this->option('dry-run')) {
                    $this->line("DRY: would run {$row->module} for {$row->owner?->email}");

                    continue;
                }
                $this->runOne($row);
                $this->info("OK   {$row->name} ({$row->module})");
            } catch (\Throwable $e) {
                Log::error('scheduled-export-failed', [
                    'id' => $row->id,
                    'module' => $row->module,
                    'message' => $e->getMessage(),
                ]);
                $this->error("FAIL {$row->name} — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function runOne(ScheduledExport $row): void
    {
        $format = $row->format instanceof ExportFormat ? $row->format : ExportFormat::Xlsx;
        $frequency = $row->frequency instanceof ExportFrequency ? $row->frequency : ExportFrequency::Daily;

        $exporter = $this->runner->build($row->module, (array) $row->columns, (array) ($row->filters ?? []));
        $filename = sprintf(
            '%s-%s.%s',
            str_replace('.', '_', $row->module),
            now()->format('Ymd-His'),
            $format->extension(),
        );
        $bytes = $this->spreadsheets->render($exporter, $format);

        $recipients = (array) ($row->recipients ?? []);
        $name = $row->name;
        $module = $row->module;
        $company = $this->settings->requiredString('company.legal_name');

        Mail::raw("Attached is your scheduled export: {$name} ({$module}).", function ($message) use ($recipients, $filename, $bytes, $name, $format, $company) {
            $message->to($recipients)
                ->subject("[{$company} ERP] Scheduled export: {$name}")
                ->attachData($bytes, $filename, [
                    'mime' => $format->mimeType(),
                ]);
        });

        $row->last_run_at = now();
        $row->next_run_at = $frequency->nextRunFrom(
            now(),
            $row->day_of_week,
            $row->day_of_month,
            (string) ($row->time_of_day ?? $this->settings->requiredString('exports.default_time_of_day')),
        );
        $row->save();
    }
}
