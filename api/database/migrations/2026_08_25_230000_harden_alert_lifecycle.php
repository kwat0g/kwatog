<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give alerts a durable condition identity and a recoverable lifecycle.
 *
 * The partial unique index is the database concurrency authority: at most one
 * unresolved row may exist for a condition, including a row the operator has
 * dismissed but the engine has not yet observed as recovered.
 */
return new class extends Migration
{
    private const INDEX = 'alerts_one_open_condition_unique';

    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->char('condition_key', 64)->nullable()->after('entity_id');
            $table->timestamp('resolved_at')->nullable()->after('dismissed_at');
            $table->string('email_status', 20)->default('pending')->after('notified_email_at');
            $table->unsignedSmallInteger('email_attempts')->default(0)->after('email_status');
            $table->timestamp('email_last_attempt_at')->nullable()->after('email_attempts');
            $table->timestamp('email_next_attempt_at')->nullable()->after('email_last_attempt_at');
            $table->timestamp('email_failed_at')->nullable()->after('email_next_attempt_at');
            $table->text('email_last_error')->nullable()->after('email_failed_at');
        });

        DB::table('alerts')->orderBy('id')->chunkById(500, function ($alerts): void {
            foreach ($alerts as $alert) {
                DB::table('alerts')
                    ->where('id', $alert->id)
                    ->update([
                        'condition_key' => hash('sha256', implode('|', [
                            (string) $alert->type,
                            (string) ($alert->entity_type ?? ''),
                            $alert->entity_id === null ? '' : (string) $alert->entity_id,
                        ])),
                    ]);
            }
        });

        // Existing releases used a time-window check, so duplicate open rows
        // may already exist. Keep the newest undismissed row when possible and
        // mark the rest recovered before adding the unique index.
        $duplicateKeys = DB::table('alerts')
            ->whereNull('resolved_at')
            ->whereNotNull('condition_key')
            ->select('condition_key')
            ->groupBy('condition_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('condition_key');

        foreach ($duplicateKeys as $conditionKey) {
            $rows = DB::table('alerts')
                ->where('condition_key', $conditionKey)
                ->whereNull('resolved_at')
                ->orderByDesc('id')
                ->get(['id', 'is_dismissed']);

            $keeper = $rows->first(static fn ($row): bool => ! (bool) $row->is_dismissed)
                ?? $rows->first();

            if ($keeper === null) {
                continue;
            }

            DB::table('alerts')
                ->where('condition_key', $conditionKey)
                ->whereNull('resolved_at')
                ->where('id', '!=', $keeper->id)
                ->update(['resolved_at' => now(), 'updated_at' => now()]);
        }

        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Alert lifecycle uniqueness requires a partial-index capable driver; received {$driver}."
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX
            .' ON alerts (condition_key) WHERE resolved_at IS NULL'
        );
        DB::statement(
            'CREATE INDEX alerts_condition_resolution_idx ON alerts (condition_key, resolved_at)'
        );
        DB::statement(
            'CREATE INDEX alerts_email_retry_idx ON alerts (email_status, email_next_attempt_at)'
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
            DB::statement('DROP INDEX IF EXISTS alerts_condition_resolution_idx');
            DB::statement('DROP INDEX IF EXISTS alerts_email_retry_idx');
        }

        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropColumn([
                'condition_key', 'resolved_at', 'email_status', 'email_attempts',
                'email_last_attempt_at', 'email_next_attempt_at', 'email_failed_at',
                'email_last_error',
            ]);
        });
    }
};
