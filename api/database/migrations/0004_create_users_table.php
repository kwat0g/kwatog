<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('password');

            $table->foreignId('role_id')->constrained('roles');
            // employee_id FK is added in Task 14 once `employees` table exists.
            $table->unsignedBigInteger('employee_id')->nullable()->index();

            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_activity')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->string('theme_mode', 10)->default('system');
            $table->boolean('sidebar_collapsed')->default(false);

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
