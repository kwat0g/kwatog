<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_for_quotes', function (Blueprint $table): void {
            $table->text('last_extension_reason')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('request_for_quotes', function (Blueprint $table): void {
            $table->dropColumn('last_extension_reason');
        });
    }
};
