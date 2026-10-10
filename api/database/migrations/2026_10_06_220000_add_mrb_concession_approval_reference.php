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
        Schema::table('material_review_records', fn (Blueprint $table) => $table->string('concession_approval_reference', 500)->nullable());
    }

    public function down(): void
    {
        if (DB::table('material_review_records')->whereNotNull('concession_approval_reference')->exists()) {
            throw new RuntimeException('Concession evidence must be preserved before removing its column.');
        }
        Schema::table('material_review_records', fn (Blueprint $table) => $table->dropColumn('concession_approval_reference'));
    }
};
