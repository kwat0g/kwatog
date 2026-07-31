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
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        $defaultId = DB::table('shifts')
            ->where('is_active', true)
            ->orderByRaw("case when name = 'Day Shift' then 0 else 1 end")
            ->orderBy('id')
            ->value('id');

        if ($defaultId !== null) {
            DB::table('shifts')->where('id', $defaultId)->update(['is_default' => true]);
        }

        DB::statement('CREATE UNIQUE INDEX shifts_one_default_idx ON shifts (is_default) WHERE is_default = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shifts_one_default_idx');
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
