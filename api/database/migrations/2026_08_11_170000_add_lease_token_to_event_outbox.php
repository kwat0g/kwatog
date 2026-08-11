<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fence stale outbox workers after a lease is reclaimed.
 *
 * A timestamp tells us that a lease is old, but it does not identify the
 * worker that owns the current lease. A crashed worker can therefore resume
 * after another worker reclaimed the row and overwrite the newer worker's
 * published/pending state. Each claim gets a fresh token; terminal writes
 * must present that token before they are allowed to mutate the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_outbox') || Schema::hasColumn('event_outbox', 'lease_token')) {
            return;
        }

        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->uuid('lease_token')->nullable()->index()->after('locked_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_outbox') || ! Schema::hasColumn('event_outbox', 'lease_token')) {
            return;
        }

        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->dropIndex(['lease_token']);
            $table->dropColumn('lease_token');
        });
    }
};
