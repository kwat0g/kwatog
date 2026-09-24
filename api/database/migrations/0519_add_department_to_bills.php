<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bills', 'department_id')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table): void {
            $table->foreignId('department_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
