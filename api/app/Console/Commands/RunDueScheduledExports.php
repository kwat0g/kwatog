<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Enums\ExportFormat;
use App\Common\Enums\ExportFrequency;
use App\Common\Mail\ScheduledExportMail;
use App\Common\Models\ScheduledExport;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\Export\ExportRunner;
use App\Common\Services\Export\SpreadsheetExportService;
use App\Common\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Series E (Task E2) — every-5-min scheduler tick. For each due export:
 *   1. Build the exporter via ExportRunner.
 *   2. Render to a temp file.
 *   3. Mail it to recipients.
 *   4. Update last_run_at + next_run_at.
 *
 * Mail delivery uses a dedicated Mailable so the export metadata and
 * attachment are rendered consistently for every export type.
 */
class RunDueScheduledExports extends Command
{
    private const LEASE_MINUTES = 30;

    protected $signature = 'exports:run-due {--dry-run : List due rows without sending}';

    protected $description = 'Run all scheduled exports whose next_run_at has elapsed.';

    public function __construct(
        private readonly ExportRunner $runner,
        private readonly SpreadsheetExportService $spreadsheets,
        private readonly SettingsService $settings,
        private readonly EmailDeliveryFailureNotifier $emailFailures,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $scanAt = now();
        $due = ScheduledExport::query()->due($scanAt)->with('owner:id,name,email')->get();

        $this->info("Found {$due->count()} due export(s).");
        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($due as $row) {
            $token = null;
            try {
                if ($this->option('dry-run')) {
                    $this->line("DRY: would run {$row->module} for {$row->owner?->email}");

                    continue;
                }
                $token = (string) Str::uuid();
                if (! $this->claim($row, $scanAt, $token)) {
                    $this->line("SKIP {$row->name} — another runner claimed it.");

                    continue;
                }

                $this->runOne($row, $token, $scanAt);
                $this->info("OK   {$row->name} ({$row->module})");
            } catch (\Throwable $e) {
                $failed++;
                if ($token !== null) {
                    $this->releaseAfterFailure($row, $token, $e);
                }
                $this->emailFailures->notify(
                    $row->owner,
                    'Scheduled export',
                    "The scheduled export '{$row->name}' could not be delivered. Review the export configuration and run it again or use an approved alternate channel.",
                    [
                        'link_to' => '/admin/scheduled-exports',
                        'entity_type' => 'scheduled_export',
                        'entity_id' => $row->hash_id,
                        'reason' => 'The export email or export generation failed.',
                    ],
                );
                Log::error('scheduled-export-failed', [
                    'id' => $row->id,
                    'module' => $row->module,
                    'message' => $e->getMessage(),
                ]);
                $this->error("FAIL {$row->name} — {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function claim(ScheduledExport $row, Carbon $scanAt, string $token): bool
    {
        $claimed = ScheduledExport::query()
            ->whereKey($row->getKey())
            ->where('is_active', true)
            ->where('next_run_at', '<=', $scanAt)
            ->where(function ($query) use ($scanAt): void {
                $query->whereNull('processing_until')
                    ->orWhere('processing_until', '<=', $scanAt);
            })
            ->update([
                'processing_token' => $token,
                'processing_started_at' => $scanAt,
                'processing_until' => $scanAt->copy()->addMinutes(self::LEASE_MINUTES),
                'last_attempt_at' => $scanAt,
                'last_error' => null,
            ]);

        return $claimed === 1;
    }

    private function runOne(ScheduledExport $row, string $token, Carbon $runAt): void
    {
        $format = $row->format instanceof ExportFormat ? $row->format : ExportFormat::Xlsx;
        $frequency = $row->frequency instanceof ExportFrequency ? $row->frequency : ExportFrequency::Daily;
        $recipients = array_values(array_filter(
            (array) ($row->recipients ?? []),
            static fn (mixed $recipient): bool => is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false,
        ));
        if ($recipients === []) {
            throw new \RuntimeException('Scheduled export has no valid recipients.');
        }

        $exporter = $this->runner->build($row->module, (array) $row->columns, (array) ($row->filters ?? []));
        $filename = sprintf(
            '%s-%s.%s',
            str_replace('.', '_', $row->module),
            now()->format('Ymd-His'),
            $format->extension(),
        );
        $bytes = $this->spreadsheets->render($exporter, $format);

        $name = $row->name;
        $module = $row->module;
        Mail::to($recipients)->queue(new ScheduledExportMail(
            $name,
            $module,
            $filename,
            $bytes,
            $format,
            $row->owner?->id,
        ));

        $nextRunAt = $frequency->nextRunFrom(
            $runAt,
            $row->day_of_week,
            $row->day_of_month,
            (string) ($row->time_of_day ?? $this->settings->requiredString('exports.default_time_of_day')),
        );

        $updated = ScheduledExport::query()
            ->whereKey($row->getKey())
            ->where('processing_token', $token)
            ->update([
                'last_run_at' => $runAt,
                'next_run_at' => $nextRunAt,
                'last_error' => null,
                'processing_token' => null,
                'processing_started_at' => null,
                'processing_until' => null,
            ]);

        if ($updated !== 1) {
            throw new \RuntimeException('Scheduled export lease was lost before completion was recorded.');
        }
    }

    private function releaseAfterFailure(ScheduledExport $row, string $token, \Throwable $exception): void
    {
        ScheduledExport::query()
            ->whereKey($row->getKey())
            ->where('processing_token', $token)
            ->update([
                'last_error' => Str::limit($exception->getMessage(), 2000),
                'processing_token' => null,
                'processing_started_at' => null,
                'processing_until' => null,
            ]);
    }
}
