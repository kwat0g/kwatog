<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_transfers', function (Blueprint $table): void {
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('budget_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn('rejected_at');
        });
    }
};
