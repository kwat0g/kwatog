<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'alerts.ap.due_soon_days')->update([
            'label' => 'AP Due-soon Window (Days)',
            'description' => 'Inclusive number of days ahead, including today, in which an unpaid bill raises an informational alert.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'alerts.ap.due_soon_days')->update([
            'label' => 'AP Due-soon Days',
            'description' => 'Days before a bill due date when an informational alert is raised.',
            'updated_at' => now(),
        ]);
    }
};
