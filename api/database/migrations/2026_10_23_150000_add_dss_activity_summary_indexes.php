<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dss_recommendation_events', function (Blueprint $table): void {
            $table->index(['created_at', 'event'], 'dss_rec_events_created_event_idx');
        });

        Schema::table('dss_recommendations', function (Blueprint $table): void {
            $table->index('created_at', 'dss_recommendations_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dss_recommendation_events', function (Blueprint $table): void {
            $table->dropIndex('dss_rec_events_created_event_idx');
        });

        Schema::table('dss_recommendations', function (Blueprint $table): void {
            $table->dropIndex('dss_recommendations_created_at_idx');
        });
    }
};
