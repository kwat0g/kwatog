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

    private function compactAuditAction(string $event): string
    {
        return match ($event) {
            'login.success' => 'login.ok',
            'login.failed' => 'login.fail',
            'login.locked' => 'login.locked',
            'login.locked_threshold' => 'login.threshold',
            'portal.password.changed' => 'portal_pw.changed',
            'portal.password.reset_requested' => 'portal_pw.request',
            'portal.password.reset' => 'portal_pw.reset',
            default => mb_substr($event, 0, 20),
        };
    }
}
