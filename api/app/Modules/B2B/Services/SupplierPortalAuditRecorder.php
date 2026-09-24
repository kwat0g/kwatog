<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Models\AuditLog;

/**
 * Explicit audit rows for supplier-portal actions.
 *
 * Supplier writes run as the system user (SystemUserResolver::impersonate), so
 * HasAuditLog's automatic rows name the service account. This row records the
 * real actor: the portal user and the vendor they act for.
 */
class SupplierPortalAuditRecorder
{
    public function record(string $action, object $model, int $portalUserId, int $vendorId): void
    {
        AuditLog::create([
            'user_id' => null,
            'actor_type' => 'supplier_portal',
            'action' => $action,
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'old_values' => null,
            'new_values' => [
                'portal_user_id' => $portalUserId,
                'vendor_id' => $vendorId,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'source_command' => request()?->route()?->getName() ?? 'supplier_portal',
            'correlation_id' => request()?->attributes->get('request_id') ?? request()?->header('X-Request-ID'),
            'created_at' => now(),
        ]);
    }
}
