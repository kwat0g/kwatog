<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dss_recommendations', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('dss_recommendations', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
