<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `quote_requests` was a write-only table.
 *
 * Migration 0219 created it, a controller/service/notification wrote to it, and
 * nothing in the ERP ever read it — no inbox, no sidebar entry, no page across
 * 23 route files. Every submission landed somewhere no employee could reach,
 * while the outbound notification told the sender to "log in to the ERP to
 * review and respond", a screen that did not exist.
 *
 * The form was also wrong for the business: it asked for part description,
 * annual volume and a CAD drawing, i.e. custom-tooling intake. Ogami molds to a
 * customer's existing tooling and does not accept custom mold part design.
 *
 * Reshaped rather than replaced — `ip_address`, `user_agent` and the throttle
 * story are worth keeping, and a rename preserves whatever rows exist. Columns
 * carrying tooling-intake data are dropped; their content has no meaning in the
 * new shape and no consumer ever read it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::rename('quote_requests', 'contact_inquiries');

        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->renameColumn('request_no', 'inquiry_no');
            $table->renameColumn('part_description', 'message');
        });

        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->dropColumn(['annual_volume', 'drawing_path', 'drawing_original_name']);
        });

        Schema::table('contact_inquiries', function (Blueprint $table): void {
            // A job applicant or a student asking about the plant has no company.
            $table->string('company', 150)->nullable()->change();
            $table->string('phone', 40)->nullable()->after('email');
            // Set when an inquiry is promoted into the CRM funnel. nullOnDelete
            // so deleting a lead leaves the inquiry readable rather than
            // cascading away the original message.
            $table->foreignId('converted_to_lead_id')->nullable()->after('status')
                ->constrained('leads')->nullOnDelete();
            // 0444 gave every other table soft deletes but skipped this one,
            // since it had no reader to delete from. An inbox needs to dismiss
            // spam without destroying the record behind it.
            $table->softDeletes();
        });

        // 'reviewed'/'contacted' described a quoting workflow. The inbox tracks
        // whether an inquiry was handled and whether it became a lead.
        DB::table('contact_inquiries')->whereIn('status', ['reviewed', 'contacted'])->update(['status' => 'in_progress']);
    }

    public function down(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->dropForeign(['converted_to_lead_id']);
            $table->dropColumn(['converted_to_lead_id', 'phone', 'deleted_at']);
        });

        DB::table('contact_inquiries')->where('status', 'in_progress')->update(['status' => 'reviewed']);
        DB::table('contact_inquiries')->where('status', 'converted')->update(['status' => 'contacted']);

        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->renameColumn('inquiry_no', 'request_no');
            $table->renameColumn('message', 'part_description');
        });

        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->unsignedInteger('annual_volume')->nullable();
            $table->string('drawing_path')->nullable();
            $table->string('drawing_original_name')->nullable();
        });

        // Restoring NOT NULL needs a value for rows that arrived without a
        // company; the original column had no default.
        DB::table('contact_inquiries')->whereNull('company')->update(['company' => '—']);

        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->string('company', 150)->nullable(false)->change();
        });

        Schema::rename('contact_inquiries', 'quote_requests');
    }
};
