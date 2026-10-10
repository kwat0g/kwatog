<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_logs', function (Blueprint $table): void {
            $table->text('finding')->nullable();
            $table->text('action_taken')->nullable();
            $table->string('result', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_logs', function (Blueprint $table): void {
            $table->dropColumn(['finding', 'action_taken', 'result']);
        });
    }
};
