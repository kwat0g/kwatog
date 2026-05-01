<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Top-level seeder. Calls per-module / per-domain seeders in dependency order.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Sprint 1 foundation seeders (added across Tasks 9, 10, 11, 12).
        // Each module appends its own seed step here when it lands.
    }
}
