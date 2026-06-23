<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_file_records', function (Blueprint $table) {
            $table->string('format', 20)->default('generic')->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('bank_file_records', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
