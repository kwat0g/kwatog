<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->string('defect_photo_path')->nullable();
            $table->string('defect_photo_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropColumn(['defect_photo_path', 'defect_photo_name']);
        });
    }
};
