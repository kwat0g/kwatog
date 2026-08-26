<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class AuthAuditLogger
{
    /** Width of audit_logs.action as widened by migration 0176. */
    private const ACTION_MAX_LENGTH = 40;

    public function internal(string $event, User $user, ?Request $request = null): void
    {
        $this->record(
            event: $event,
            user: $user,
            request: $request,
            auditModelType: 'auth.event',
            actorType: $event === 'password.changed' ? 'user' : 'self_service',
            auditUserId: (int) $user->getKey(),
        );
    }

    public function portal(string $event, Model $user, ?Request $request = null): void
    {
        $actorType = $user::class === \App\Modules\B2B\Models\SupplierPortalUser::class
            ? 'supplier_portal'
            : 'customer_portal';

        $this->record(
            event: $event,
            user: $user,
            request: $request,
            auditModelType: $user::class,
            actorType: $actorType,
            auditUserId: null,
        );
    }

    private function record(
        string $event,
        Model $user,
        ?Request $request,
        string $auditModelType,
        string $actorType,
        ?int $auditUserId,
    ): void {
        $email = (string) $user->getAttribute('email');
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();

        Log::channel('auth')->info($event, [
            'user_id' => $user->getKey(),
            'email' => $email,
            'audience' => $auditModelType,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        try {
            AuditLog::create([
                'user_id' => $auditUserId,
                'actor_type' => $actorType,
                'action' => $this->compactAuditAction($event),
                'model_type' => $auditModelType,
                'model_id' => $user->getKey(),
                'old_values' => null,
                'new_values' => ['email' => $email, 'event' => $event],
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'source_command' => $request?->route()?->getName() ?? $request?->path() ?? 'auth',
                'correlation_id' => $request?->attributes->get('request_id')
                    ?? $request?->header('X-Request-ID'),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::channel('auth')->warning('audit_log_mirror_failed', [
                'event' => $event,
                'user_id' => $user->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * audit_logs.action holds the event name verbatim, capped at the column width.
     *
     * This used to compact names through a lookup table ('login.success' =>
     * 'login.ok') with an mb_substr($event, 0, 20) fallback, both sized for the
     * varchar(20) the column was created with in 0008. Migration 0176 widened it
     * to varchar(40) and the compaction was never retired, so it kept silently
     * rewriting names that now fit: 'supplier.login.success' (22 chars) was stored
     * as 'supplier.login.succe', and every caller and test looking for the real
     * event name found nothing. Longest event in the codebase is 31 chars
     * ('supplier.login.locked_threshold', 'portal.password.reset_requested').
     *
     * Nothing ever read the compacted values, so dropping them breaks no consumer.
     * Rows written before this change keep their old spellings — audit_logs carries
     * an audit_logs_prevent_update trigger, so they cannot be backfilled, and a
     * query over historical data may need to match both forms.
     *
     * The cap stays as a guard: exceeding the column raises SQLSTATE 22001, and
     * the surrounding try/catch would swallow it into a log warning, losing the
     * audit row silently. Truncating is the lesser failure.
     */
    private function compactAuditAction(string $event): string
    {
        return mb_substr($event, 0, self::ACTION_MAX_LENGTH);
    }
}
