<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut — the Edge (factory-floor IoT ingestion) module was removed. No
 * table references `edge_devices`, and its only cross-module consumers were
 * re-homed first: the service-account resolver moved to
 * App\Common\Services\SystemUserResolver, and the barcode resolver moved to
 * App\Modules\Inventory\Services\BarcodeScanResolverService (the ADV8
 * warehouse scanner still uses it).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('edge_devices');
    }

    public function down(): void
    {
        // Recreated per migrations 0186 + 0187 (device registry + machine link).
        Schema::create('edge_devices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('device_type', 30);
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('machine_id');
        });
    }
};
