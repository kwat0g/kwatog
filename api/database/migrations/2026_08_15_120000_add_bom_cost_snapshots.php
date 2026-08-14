<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_of_materials', function (Blueprint $table): void {
            $table->decimal('material_cost', 15, 2)->nullable()->after('is_active');
            $table->string('cost_basis', 30)->nullable()->after('material_cost');
            $table->timestamp('costed_at')->nullable()->after('cost_basis');
            $table->json('cost_warnings')->nullable()->after('costed_at');
        });

        Schema::table('bom_items', function (Blueprint $table): void {
            $table->decimal('cost_quantity', 15, 6)->nullable()->after('waste_factor');
            $table->decimal('unit_cost', 15, 4)->nullable()->after('cost_quantity');
            $table->decimal('extended_cost', 15, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('bom_items', function (Blueprint $table): void {
            $table->dropColumn(['cost_quantity', 'unit_cost', 'extended_cost']);
        });

        Schema::table('bill_of_materials', function (Blueprint $table): void {
            $table->dropColumn(['material_cost', 'cost_basis', 'costed_at', 'cost_warnings']);
        });
    }
};
