<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->date('replacement_due_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropColumn('replacement_due_date');
        });
    }
};
