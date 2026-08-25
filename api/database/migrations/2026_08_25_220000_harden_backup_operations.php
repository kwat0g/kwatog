<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_operations')) {
            return;
        }

        Schema::table('backup_operations', function (Blueprint $table): void {
            $table->string('active_lock', 64)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();

            $table->unique('active_lock', 'backup_operations_active_lock_unique');
            $table->unique('lease_token', 'backup_operations_lease_token_unique');
            $table->index('lease_expires_at');
        });

        // Do not silently choose a winner if the old check-then-insert race
        // already left multiple active rows. Deployment must stop and let an
        // operator reconcile that state before the singleton constraint lands.
        $active = DB::table('backup_operations')
            ->whereIn('status', ['queued', 'running', 'rollback_required'])
            ->orderBy('created_at')
            ->pluck('id');

        if ($active->count() > 1) {
            throw new RuntimeException(
                'Cannot harden backup operations while multiple active rows exist; reconcile backup_operations first.'
            );
        }

        if ($active->count() === 1) {
            DB::table('backup_operations')
                ->where('id', $active->first())
                ->update(['active_lock' => 'ogami-backup-recovery']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('backup_operations')) {
            return;
        }

        Schema::table('backup_operations', function (Blueprint $table): void {
            $table->dropUnique('backup_operations_active_lock_unique');
            $table->dropUnique('backup_operations_lease_token_unique');
            $table->dropIndex(['lease_expires_at']);
            $table->dropColumn([
                'active_lock',
                'lease_token',
                'attempts',
                'heartbeat_at',
                'lease_expires_at',
            ]);
        });
    }
};
