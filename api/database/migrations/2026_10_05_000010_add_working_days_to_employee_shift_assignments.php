<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_shift_assignments', function (Blueprint $table): void {
            $table->json('working_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_shift_assignments', function (Blueprint $table): void {
            $table->dropColumn('working_days');
        });
    }
};
