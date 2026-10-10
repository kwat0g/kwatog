<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const SETTING = 'accounting.je_self_post_limit';
    private const RETIRED_SETTING = 'accounting.je_self_post_limit.retired';
    private const PERMISSION = 'accounting.journal.self_post_override';
    private const RETIRED_PERMISSION = 'accounting.journal.self_post_override.retired';

    public function up(): void
    {
        DB::table('settings')->where('key', self::SETTING)->update(['key' => self::RETIRED_SETTING]);
        DB::table('permissions')->where('slug', self::PERMISSION)->update(['slug' => self::RETIRED_PERMISSION]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::RETIRED_SETTING)->update(['key' => self::SETTING]);
        DB::table('permissions')->where('slug', self::RETIRED_PERMISSION)->update(['slug' => self::PERMISSION]);
    }
};
