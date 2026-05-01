<?php

declare(strict_types=1);

namespace App\Common\Traits;

use App\Common\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Eloquent observer that writes a row to `audit_logs` on create/update/delete.
 *
 * Encrypted casts are NEVER logged in plaintext — they appear as '***'.
 */
trait HasAuditLog
{
    public static function bootHasAuditLog(): void
    {
        static::created(fn (Model $m)  => static::writeAudit($m, 'created', null, $m->getAttributes()));
        static::updated(fn (Model $m)  => static::writeAudit($m, 'updated', $m->getOriginal(), $m->getChanges()));
        static::deleted(fn (Model $m)  => static::writeAudit($m, 'deleted', $m->getOriginal(), null));
    }

    private static function writeAudit(Model $model, string $action, ?array $old, ?array $new): void
    {
        $request = request();
        $userId = Auth::id();

        AuditLog::create([
            'user_id'    => $userId,
            'action'     => $action,
            'model_type' => $model->getMorphClass(),
            'model_id'   => $model->getKey(),
            'old_values' => $old ? static::redactSensitive($model, $old) : null,
            'new_values' => $new ? static::redactSensitive($model, $new) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function redactSensitive(Model $model, array $values): array
    {
        $casts = $model->getCasts();
        foreach ($values as $field => $_) {
            if (($casts[$field] ?? null) === 'encrypted') {
                $values[$field] = '***';
            }
        }
        return $values;
    }
}
