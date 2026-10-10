<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_payments', function (Blueprint $table): void {
            $table->string('proof_path')->nullable();
            $table->string('proof_file_name')->nullable();
            $table->string('proof_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bill_payments', fn (Blueprint $table) => $table->dropColumn([
            'proof_path', 'proof_file_name', 'proof_sha256',
        ]));
    }
};
