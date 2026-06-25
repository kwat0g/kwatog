<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('transfer_number', 30)->unique();
            $t->foreignId('asset_id')->constrained('assets');
            $t->foreignId('from_department_id')->constrained('departments');
            $t->foreignId('to_department_id')->constrained('departments');
            $t->string('reason', 500)->nullable();
            $t->string('status', 20)->default('pending');
            $t->date('transfer_date');
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();

            $t->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_transfers');
    }
};
