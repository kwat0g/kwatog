<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'leave.year_end.automation_user_email',
            'value' => json_encode('system@ogami.local'),
            'group' => 'leave',
            'label' => 'Year-end Leave Automation User Email',
            'description' => 'Preferred service-user email used to attribute year-end leave processing jobs.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'leave.year_end.automation_user_email')->delete();
    }
};
