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
        if (! Schema::hasColumn('employee_shift_assignments', 'updated_at')) {
            Schema::table('employee_shift_assignments', function (Blueprint $table): void {
                $table->timestamp('updated_at')->nullable();
            });
        }

        DB::table('employee_shift_assignments')
            ->whereNull('updated_at')
            ->whereNotNull('created_at')
            ->update(['updated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_shift_assignments', 'updated_at')) {
            Schema::table('employee_shift_assignments', function (Blueprint $table): void {
                $table->dropColumn('updated_at');
            });
        }
    }
};
