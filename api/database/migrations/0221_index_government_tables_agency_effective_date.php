<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('government_contribution_tables', function (Blueprint $table) {
            $table->index(['agency', 'effective_date'], 'gct_agency_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::table('government_contribution_tables', function (Blueprint $table) {
            $table->dropIndex('gct_agency_effective_idx');
        });
    }
};
