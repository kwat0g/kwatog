<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of Accounts (front-loaded by Sprint 3 / Task 29 so payroll GL posting
 * can run before Sprint 4 lands. Sprint 4 / Task 31 re-uses this migration via
 * `Schema::hasTable` guard if needed; the seeder is fully idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounts')) return;

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('type', 20); // asset | liability | equity | revenue | expense
            $table->string('normal_balance', 10); // debit | credit
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
