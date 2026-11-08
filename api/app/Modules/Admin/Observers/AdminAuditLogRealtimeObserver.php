<?php

declare(strict_types=1);

namespace App\Modules\Admin\Observers;

use App\Common\Models\AuditLog;
use App\Modules\Admin\Services\AdminAuditLogRealtimeService;

/** Stages one refresh signal for append-only audit-log writes across all modules. */
final class AdminAuditLogRealtimeObserver
{
    public function created(AuditLog $auditLog): void
    {
        app(AdminAuditLogRealtimeService::class)->stage();
    }
}
