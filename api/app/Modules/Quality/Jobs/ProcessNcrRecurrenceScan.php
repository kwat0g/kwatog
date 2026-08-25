<?php

declare(strict_types=1);

namespace App\Modules\Quality\Jobs;

use App\Modules\Quality\Models\NcrRecurrenceScan;
use App\Modules\Quality\Services\NcrRecurrenceDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Retryable, post-commit recurrence linking for a newly-created NCR. */
class ProcessNcrRecurrenceScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $ncrId) {}

    public function handle(NcrRecurrenceDetector $detector): void
    {
        $claimed = DB::transaction(function (): bool {
            $scan = NcrRecurrenceScan::query()
                ->where('ncr_id', $this->ncrId)
                ->lockForUpdate()
                ->first();

            if (! $scan || $scan->status === 'completed') {
                return false;
            }

            if (
                $scan->status === 'running'
                && $scan->started_at?->gt(now()->subMinutes(15))
            ) {
                return false;
            }

            if ($scan->available_at?->isFuture()) {
                return false;
            }

            $scan->forceFill([
                'status'     => 'running',
                'attempts'   => $scan->attempts + 1,
                'started_at' => now(),
                'last_error' => null,
            ])->save();

            return true;
        });

        if (! $claimed) {
            return;
        }

        try {
            $detector->scan($this->ncrId);

            DB::transaction(function () use ($detector): void {
                $scan = NcrRecurrenceScan::query()
                    ->where('ncr_id', $this->ncrId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $ncr = $scan->ncr()->firstOrFail();

                if ($ncr->recurrence_of_ncr_id && $scan->notification_sent_at === null) {
                    // NotificationService inserts inbox rows in this same
                    // transaction. The sent marker and the rows therefore
                    // commit or roll back together, making a worker retry
                    // safe even if the marker update fails.
                    $recipients = $detector->notify($ncr);
                    if ($recipients < 1) {
                        throw new \RuntimeException('NCR recurrence notification produced no recipients.');
                    }
                    $scan->forceFill(['notification_sent_at' => now()])->save();
                }

                $scan->forceFill([
                    'status'       => 'completed',
                    'completed_at' => now(),
                    'last_error'   => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            NcrRecurrenceScan::query()
                ->where('ncr_id', $this->ncrId)
                ->update([
                    'status'       => 'pending',
                    'available_at' => now(),
                    'last_error'   => mb_substr($exception->getMessage(), 0, 5000),
                    'updated_at'   => now(),
                ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        NcrRecurrenceScan::query()
            ->where('ncr_id', $this->ncrId)
            ->update([
                'status'       => 'failed',
                'last_error'   => mb_substr($exception->getMessage(), 0, 5000),
                'updated_at'   => now(),
            ]);

        Log::error('NCR recurrence scan failed permanently.', [
            'ncr_id'    => $this->ncrId,
            'exception' => $exception::class,
            'message'   => $exception->getMessage(),
        ]);
    }
}
