<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Common\Models\AuditLog;
use App\Common\Services\ActivityFeedService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Projects the canonical auth audit rows into the admin activity feed. */
class RecordAuthActivity
{
    public function __construct(private readonly ActivityFeedService $feed) {}

    public function created(AuditLog $log): void
    {
        if ($log->model_type !== 'auth.event' || $log->model_id === null) {
            return;
        }

        try {
            $action = (string) $log->action;
            $detail = is_array($log->new_values) ? $log->new_values : [];
            $severity = str_contains($action, 'failed') || str_contains($action, 'locked')
                ? 'danger'
                : ($action === 'login.success' || $action === 'password.changed' ? 'success' : 'info');

            $this->feed->record(
                type: 'auth',
                action: $action,
                subject: ['App\\Modules\\Auth\\Models\\User', (int) $log->model_id],
                summary: 'Authentication — '.Str::headline(str_replace('.', ' ', $action)),
                detail: $detail,
                link: '/admin/audit-logs/'.$log->hash_id,
                severity: $severity,
                idempotencyKey: hash('sha256', 'audit-log:'.$log->getKey()),
                actorUserId: $log->user_id ? (int) $log->user_id : null,
                actorType: $log->actor_type,
                ipAddress: $log->ip_address,
                createdAt: $log->created_at,
            );
        } catch (Throwable $e) {
            Log::warning('Auth activity projection failed.', [
                'audit_log_id' => $log->getKey(),
                'exception' => $e,
            ]);
        }
    }
}
