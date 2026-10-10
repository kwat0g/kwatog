<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE work_order_outputs ALTER COLUMN shift TYPE VARCHAR(50)');
    }

    public function down(): void {}
};
