<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) return;
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('actor_type', 20)->nullable()->index();
            $table->string('source_command', 160)->nullable();
            $table->string('correlation_id', 128)->nullable()->index();
            $table->text('reason')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs')) return;
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn(['actor_type', 'source_command', 'correlation_id', 'reason']);
        });
    }
};
