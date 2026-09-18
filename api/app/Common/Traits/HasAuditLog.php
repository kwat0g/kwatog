<?php

declare(strict_types=1);

namespace App\Common\Traits;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\User;
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

    /**
     * Let a model replace raw column values with decrypted/array values for the
     * audit trail. Needed when a model uses a custom encrypted cast whose class
     * name `redactValue()` cannot recognise: without this the audit stored the
     * ciphertext string and nested redaction (e.g. changes.bank_account) never
     * ran. Only keys already present in the snapshot are replaced.
     */
    private static function auditSnapshot(Model $model, ?array $values): ?array
    {
        if ($values === null || ! method_exists($model, 'auditAttributeSnapshot')) {
            return $values;
        }

        foreach ($model->auditAttributeSnapshot() as $key => $value) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private static function writeAudit(Model $model, string $action, ?array $old, ?array $new): void
    {
        $request = request();
        $context = method_exists($model, 'auditContextOverride')
            ? $model->auditContextOverride()
            : null;
        [$userId, $actorType] = is_array($context)
            ? [$context['user_id'] ?? null, $context['actor_type'] ?? 'system']
            : static::auditActor();
        $source = $request?->attributes->get('source_command')
            ?? $request?->route()?->getName()
            ?? (app()->runningInConsole() ? 'console' : 'unknown');
        $correlation = $request?->attributes->get('request_id')
            ?? $request?->header('X-Request-ID');

        AuditLog::create([
            'user_id'    => $userId,
            'actor_type' => $actorType,
            'action'     => $action,
            'model_type' => $model->getMorphClass(),
            'model_id'   => $model->getKey(),
            'old_values' => $old ? static::redactSensitive($model, static::auditSnapshot($model, $old)) : null,
            'new_values' => $new ? static::redactSensitive($model, static::auditSnapshot($model, $new)) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'source_command' => (string) $source,
            'correlation_id' => $correlation,
            'reason' => ($context['reason'] ?? null) ?: static::auditReason($old, $new),
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
        return static::redactValue($values, $casts);
    }

    private static function redactValue(mixed $value, array $casts = [], ?string $field = null): mixed
    {
        $name = strtolower((string) $field);
        $cast = strtolower((string) ($field !== null ? ($casts[$field] ?? '') : ''));
        if (($field !== null && str_starts_with((string) ($casts[$field] ?? ''), 'encrypted'))
            || preg_match('/(?:bank|account|iban|routing|swift|password|secret|token|ssn|tin|sss|philhealth|pagibig|national.?id|tax.?id)/i', $name)) {
            return '***';
        }
        if (is_string($value) && in_array($cast, ['array', 'json', 'object', 'collection'], true)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) $redacted[$key] = static::redactValue($item, $casts, (string) $key);
            return $redacted;
        }
        return $value;
    }

    /** @return array{0:?int,1:string} */
    private static function auditActor(): array
    {
        $id = Auth::id();
        if ($id !== null && User::query()->whereKey($id)->exists()) {
            return [(int) $id, 'user'];
        }

        // Do not lazily provision a user from inside an Eloquent model event.
        // A missing setting/role can raise a database error that leaves the
        // caller's PostgreSQL transaction aborted even when caught. System and
        // portal actions are represented explicitly by actor_type with a
        // nullable user_id instead.
        return [null, 'system'];
    }

    private static function auditReason(?array $old, ?array $new): ?string
    {
        foreach (['reason', 'reason_code', 'note', 'review_remarks', 'finance_remarks', 'remarks', 'notes'] as $field) {
            $value = $new[$field] ?? $old[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') return mb_substr(trim((string) $value), 0, 2000);
        }
        return null;
    }
}
