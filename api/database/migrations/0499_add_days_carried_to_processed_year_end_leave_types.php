<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processed_year_end_leave_types', function (Blueprint $table): void {
            $table->decimal('days_carried', 8, 1)->default(0)->after('days_forfeited');
        });
    }

    public function down(): void
    {
        Schema::table('processed_year_end_leave_types', function (Blueprint $table): void {
            $table->dropColumn('days_carried');
        });
    }
};
