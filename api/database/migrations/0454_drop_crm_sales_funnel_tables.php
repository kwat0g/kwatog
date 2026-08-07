<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut — remove the CRM sales funnel (leads, opportunities, quotes,
 * commissions).
 *
 * The funnel modelled work that happens BEFORE Chain 1 (Order to Cash) begins.
 * Ogami is a tier-1 automotive supplier: demand arrives as a released customer
 * schedule against a signed price agreement, not as a lead that a rep nurtures
 * through a probability-weighted pipeline. Sales orders were always created
 * directly, so `quotes.converted_to_sales_order_id` and
 * `leads.converted_to_opportunity_id` stayed null in every environment.
 *
 * All five tables were empty at the time of this migration
 * (`commission_entries` was never created — only the rate table shipped).
 *
 * `contact_inquiries` survives as a plain inbox for the public contact form;
 * only its pointer into `leads` is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->dropForeign(['converted_to_lead_id']);
            $table->dropColumn('converted_to_lead_id');
        });

        // Child-first: quote_items → quotes, opportunities → leads.
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_rates');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');

        // leads and opportunities reference each other; break the cycle first.
        if (Schema::hasTable('leads')) {
            Schema::table('leads', function (Blueprint $table): void {
                $table->dropForeign(['converted_to_opportunity_id']);
            });
        }

        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('leads');
    }

    public function down(): void
    {
        // Irreversible by design: this is a scope cut, not a reversible change.
        // The tables carried no data, and the application code that read them
        // (models, services, controllers, routes, SPA pages, permissions) was
        // deleted in the same commit. Restoring the schema alone would leave
        // orphan tables no code can reach. Recover from git history instead.
        throw new RuntimeException(
            'Irreversible scope cut. Restore commit "'.
            'refactor: cut CRM sales funnel" from git history to reinstate the funnel.'
        );
    }
};
