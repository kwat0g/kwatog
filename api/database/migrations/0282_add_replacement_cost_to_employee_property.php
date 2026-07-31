<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_property', function (Blueprint $table): void {
            $table->decimal('replacement_unit_cost', 15, 2)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('employee_property', function (Blueprint $table): void {
            $table->dropColumn('replacement_unit_cost');
        });
    }
};
