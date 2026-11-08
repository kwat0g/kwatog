<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Common\Models\Document;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Services\EmployeeDocumentsRealtimeService;

/** Captures EmployeeDocument and generated Employee-vault Document lifecycles. */
final class EmployeeDocumentsRealtimeObserver
{
    public function created(EmployeeDocument|Document $document): void
    {
        app(EmployeeDocumentsRealtimeService::class)->stage($document, 'created');
    }

    public function updated(EmployeeDocument|Document $document): void
    {
        $action = $document->wasChanged('deleted_at') && $document->deleted_at === null
            ? 'restored'
            : 'updated';

        app(EmployeeDocumentsRealtimeService::class)->stage($document, $action);
    }

    public function deleted(EmployeeDocument|Document $document): void
    {
        if ($document instanceof Document && $document->entity_type !== Employee::class) {
            return;
        }

        app(EmployeeDocumentsRealtimeService::class)->stage($document, 'deleted');
    }
}
