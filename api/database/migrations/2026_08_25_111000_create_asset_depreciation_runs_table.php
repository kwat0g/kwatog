<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->nullOnDelete();
            $table->unsignedInteger('posted_count')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_runs');
    }
};
