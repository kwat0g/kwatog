<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->foreignId('machine_id')
                ->nullable()
                ->after('location')
                ->constrained('machines')
                ->nullOnDelete();
            $table->index('machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_id');
        });
    }
};
