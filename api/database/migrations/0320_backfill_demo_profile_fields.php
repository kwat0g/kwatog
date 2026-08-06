<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void
    {
        // Historical demo backfill intentionally disabled. Profile values must
        // come from HR imports, employee self-service requests, or live APIs;
        // never derive addresses, contacts, banks, or account numbers.
    }

    public function down(): void
    {
        // No data was written by the current migration implementation.
    }
};
