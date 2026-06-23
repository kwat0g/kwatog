<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->char('abc_class', 1)->nullable()->after('is_active')
                ->comment('ABC inventory classification: A (high value), B (medium), C (low)');
        });

        DB::statement('ALTER TABLE items ADD CONSTRAINT items_abc_class_check CHECK (abc_class IN (\'A\', \'B\', \'C\'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_abc_class_check');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('abc_class');
        });
    }
};
