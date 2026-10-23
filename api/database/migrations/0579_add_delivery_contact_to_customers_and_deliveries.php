<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chain 1 (Order to Cash) — the driver needs to know where the goods go.
 *
 * `customers.address` is the billing/registered address, and printing it on a
 * run sheet sends the truck to the wrong gate: automotive customers receive at
 * a plant or warehouse that is not their invoicing address. So the customer
 * master gains a dedicated ship-to address and the person who signs for the
 * goods at that gate.
 *
 * `deliveries` carries the same three fields as a SNAPSHOT. The run sheet is an
 * instruction that was issued, not a live view of master data: editing a
 * customer's address next month must not rewrite where last month's truck was
 * told to go (the same reasoning that pins `scheduled_date` and the dispatch
 * assignment). The snapshot is written when the delivery is drafted and
 * refreshed while the shipment plan is still editable, then frozen at dispatch.
 *
 * Numbered after 0578 by listing the directory, not by incrementing a remembered
 * maximum: only 0047 (customers) and 0096 (deliveries) are touched, and both are
 * numbered migrations, so a `0NNN_` prefix sorts correctly ahead of every
 * timestamped one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->text('delivery_address')->nullable();
            $table->string('delivery_contact_person', 100)->nullable();
            $table->string('delivery_contact_phone', 20)->nullable();
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->text('delivery_address')->nullable();
            $table->string('delivery_contact_person', 100)->nullable();
            $table->string('delivery_contact_phone', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['delivery_address', 'delivery_contact_person', 'delivery_contact_phone']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['delivery_address', 'delivery_contact_person', 'delivery_contact_phone']);
        });
    }
};
