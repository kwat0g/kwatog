<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Link quality inspections to calibrated measuring equipment per IATF 16949 §7.1.5.1. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            if (! Schema::hasColumn('inspections', 'calibration_record_id')) {
                $table->foreignId('calibration_record_id')
                    ->nullable()
                    ->after('inspector_id')
                    ->constrained('calibration_records')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            if (Schema::hasColumn('inspections', 'calibration_record_id')) {
                $table->dropConstrainedForeignId('calibration_record_id');
            }
        });
    }
};
