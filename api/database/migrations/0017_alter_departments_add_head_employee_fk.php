<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The column was created in 0014 as plain unsignedBigInteger; constrain it
        // now that the employees table exists.
        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('head_employee_id')
                ->references('id')->on('employees')
                ->nullOnDelete();
        });

        // Also link users.employee_id (column may already exist from Task 9 / Sprint 1).
        if (Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', function (Blueprint $table) {
                // Some Sprint 1 setups left it unconstrained.
                try {
                    $table->foreign('employee_id')
                        ->references('id')->on('employees')
                        ->nullOnDelete();
                } catch (\Throwable $e) {
                    // FK already present — ignore.
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            try { $table->dropForeign(['head_employee_id']); } catch (\Throwable $e) {}
        });
    }
};
