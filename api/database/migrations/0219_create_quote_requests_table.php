<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public "request a quote" submissions from the landing page.
 *
 * No foreign keys — submissions arrive before any user account exists.
 * Drawing files are stored on the local disk and referenced by path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 30)->unique();
            $table->string('full_name', 150);
            $table->string('company', 150);
            $table->string('email', 150);
            $table->text('part_description');
            $table->unsignedInteger('annual_volume')->nullable();
            $table->string('drawing_path')->nullable();
            $table->string('drawing_original_name')->nullable();
            $table->string('status', 20)->default('new');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
    }
};
