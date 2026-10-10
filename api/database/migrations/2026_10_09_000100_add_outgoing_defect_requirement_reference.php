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
        Schema::table('inspection_defective_pieces', function (Blueprint $table): void {
            $table->foreignId('requirement_id')->nullable()->constrained('inspection_measurements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('inspections')->where('counted_capture->version', 3)->exists()) {
            throw new RuntimeException('Preserve confirmed-check inspection evidence; disable new creation instead of dropping its schema.');
        }
        Schema::table('inspection_defective_pieces', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requirement_id');
        });
    }
};
