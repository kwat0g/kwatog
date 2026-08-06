<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void
    {
        // Historical demo backfill intentionally disabled. Real employee
        // profile data must be supplied by HR/import/self-service workflows.
    }

    public function down(): void {}
};
