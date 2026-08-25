<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('government_contribution_tables', function (Blueprint $table): void {
            $table->unique(
                ['agency', 'effective_date', 'bracket_min', 'bracket_max'],
                'gct_agency_date_bracket_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('government_contribution_tables', function (Blueprint $table): void {
            $table->dropUnique('gct_agency_date_bracket_unique');
        });
    }
};
