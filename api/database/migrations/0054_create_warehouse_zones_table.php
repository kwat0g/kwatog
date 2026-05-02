<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('code', 10);
            $table->string('zone_type', 30); // raw_materials/staging/finished_goods/spare_parts/quarantine/scrap
            $table->timestamps();

            $table->unique(['warehouse_id', 'code'], 'warehouse_zones_wh_code_unique');
            $table->index('zone_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_zones');
    }
};
