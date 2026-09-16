<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a visitor accepted the privacy notice on each public form that
 * collects personal data (contact inquiry, newsletter, job application). The
 * value is the evidence of consent; the checkbox itself is validated by each
 * FormRequest's `accepted` rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->timestamp('consent_at')->nullable()->after('user_agent');
        });

        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            $table->timestamp('consent_at')->nullable()->after('ip_address');
        });

        Schema::table('job_applications', function (Blueprint $table): void {
            $table->timestamp('consent_at')->nullable()->after('cover_letter');
        });
    }

    public function down(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->dropColumn('consent_at');
        });

        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            $table->dropColumn('consent_at');
        });

        Schema::table('job_applications', function (Blueprint $table): void {
            $table->dropColumn('consent_at');
        });
    }
};
