<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep scheduled-export execution recoverable and observable.
 *
 * Rendering and mail delivery happen outside a database transaction. A lease
 * prevents two scheduler instances from processing the same row concurrently,
 * while the attempt/error fields make failed or abandoned exports visible.
 * Delivery remains at-least-once if a process dies after mail acceptance but
 * before the success update.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('scheduled_exports', function (Blueprint $table): void {
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('processing_token', 64)->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_until')->nullable();

            $table->index('processing_until', 'scheduled_exports_processing_until_idx');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_exports', function (Blueprint $table): void {
            $table->dropIndex('scheduled_exports_processing_until_idx');
            $table->dropColumn([
                'last_attempt_at',
                'last_error',
                'processing_token',
                'processing_started_at',
                'processing_until',
            ]);
        });
    }
};
