<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dss_recommendations', function (Blueprint $table): void {
            $table->jsonb('owner_permissions')->nullable();
        });
        DB::statement('CREATE INDEX dss_recommendations_owner_permissions_gin_idx ON dss_recommendations USING GIN (owner_permissions)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dss_recommendations_owner_permissions_gin_idx');
        Schema::table('dss_recommendations', function (Blueprint $table): void {
            $table->dropColumn('owner_permissions');
        });
    }
};
