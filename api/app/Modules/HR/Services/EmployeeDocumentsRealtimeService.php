<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Models\Document;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\EmployeeDocumentsChanged;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces both employee document stores into one authorized list refresh. */
final class EmployeeDocumentsRealtimeService
{
    public function stage(EmployeeDocument|Document $document, string $action): void
    {
        if ($document instanceof EmployeeDocument) {
            $employeeId = (int) $document->employee_id;
        } else {
            if ($document->entity_type !== Employee::class || $document->entity_id === null) {
                return;
            }
            $employeeId = (int) $document->entity_id;
        }

        if ($employeeId <= 0) {
            return;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $actorHashId = $actor instanceof User ? (string) $actor->hash_id : null;

        app(OutboxService::class)->recordCoalesced(new EmployeeDocumentsChanged(
            employeeHashId: (string) app('hashids')->encode($employeeId),
            action: $action,
            version: $document->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $actorHashId,
        ), "employee-documents-refresh:{$employeeId}:{$transactionId}");
    }
}
