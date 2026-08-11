<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxService;
use App\Modules\Maintenance\Events\PreventiveMaintenanceGenerationRequested;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stage one durable preventive/predictive-maintenance sweep request. */
class RequestPreventiveMaintenanceGeneration extends Command
{
    protected $signature = 'maintenance:request-preventive-generation
        {--request-id= : Optional operator-supplied request identifier}
        {--force : Create a new request even when this request identifier was already staged}';

    protected $description = 'Stage the daily preventive and predictive maintenance sweep durably.';

    public function handle(OutboxService $outbox): int
    {
        $requestId = trim((string) ($this->option('request-id') ?: now()->toDateString()));
        if ($requestId === '' || ! preg_match('/^[A-Za-z0-9_.:-]+$/', $requestId)) {
            $this->error('The request identifier may contain only letters, digits, dots, underscores, colons, and hyphens.');

            return self::FAILURE;
        }

        $dedupeKey = 'maintenance-preventive:'.$requestId;
        if ($this->option('force')) {
            $requestId .= ':'.Str::uuid();
            $dedupeKey .= ':'.$requestId;
        }

        /** @var OutboxMessage $message */
        $message = DB::transaction(fn (): OutboxMessage => $outbox->record(
            new PreventiveMaintenanceGenerationRequested($requestId),
            dedupeKey: $dedupeKey,
        ));

        $this->info("Staged durable preventive-maintenance generation request {$requestId} (outbox {$message->getKey()}).");

        return self::SUCCESS;
    }
}
