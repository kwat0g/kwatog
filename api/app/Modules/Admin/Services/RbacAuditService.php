<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the authoritative audit row for RBAC mutations.
 *
 * RBAC models intentionally use explicit service-level audit writes so a
 * lifecycle mutation has one rich, stable row rather than a generic observer
 * row plus a second service row.
 */
class RbacAuditService
{
    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        Model $model,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?User $actor = null,
        ?string $reason = null,
    ): AuditLog {
        $request = request();
        $authenticatedActor = Auth::user();
        $actor ??= $authenticatedActor instanceof User ? $authenticatedActor : null;
        $source = $request?->attributes->get('source_command')
            ?? $request?->route()?->getName()
            ?? (app()->runningInConsole() ? 'console' : 'unknown');
        $correlation = $request?->attributes->get('request_id')
            ?? $request?->header('X-Request-ID');

        $audit = AuditLog::create([
            'user_id' => $actor?->getKey(),
            'actor_type' => $actor instanceof User ? 'user' : 'system',
            'action' => $action,
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'source_command' => (string) $source,
            'correlation_id' => $correlation,
            'reason' => $reason ?? $this->reasonFromRequest($request, $oldValues, $newValues),
            'created_at' => now(),
        ]);

        $model->setAttribute('last_modified_meta', [
            'by' => $actor?->name,
            'at' => $audit->created_at?->toIso8601String(),
        ]);

        return $audit;
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    private function reasonFromRequest($request, ?array $oldValues, ?array $newValues): ?string
    {
        $requestReason = $request?->input('reason');
        if (is_scalar($requestReason) && trim((string) $requestReason) !== '') {
            return mb_substr(trim((string) $requestReason), 0, 2000);
        }

        foreach (['reason', 'reason_code', 'note', 'remarks', 'notes'] as $field) {
            $value = $newValues[$field] ?? $oldValues[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 2000);
            }
        }

        return null;
    }
}
