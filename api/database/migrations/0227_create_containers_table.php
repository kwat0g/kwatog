<?php

declare(strict_types=1);

use App\Modules\SupplyChain\Enums\ContainerSize;
use App\Modules\SupplyChain\Enums\ContainerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('container_number', 50);
            $table->string('seal_number', 50)->nullable();
            $table->string('size', 20)->default(ContainerSize::FortyFt->value);
            $table->string('type', 20)->default(ContainerType::Dry->value);
            $table->decimal('gross_weight_kg', 10, 2)->nullable();
            $table->decimal('net_weight_kg', 10, 2)->nullable();
            $table->decimal('volume_cbm', 8, 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('container_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('containers');
    }
};
