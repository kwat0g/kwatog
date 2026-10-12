<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTING = 'approvals.escalation.superior_role_map';

    public function up(): void
    {
        $setting = DB::table('settings')->where('key', self::SETTING)->first();
        $map = $setting ? json_decode((string) $setting->value, true) : [];
        $map = is_array($map) ? $map : [];
        $map['vice_president'] ??= 'system_admin';

        DB::table('settings')->updateOrInsert(
            ['key' => self::SETTING],
            [
                'value' => json_encode($map, JSON_THROW_ON_ERROR),
                'group' => 'approval',
                'label' => 'Approval Escalation Superior Roles',
                'description' => 'Role-to-role escalation map. VP escalation notifies system administrators; it does not assign approval authority.',
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $setting = DB::table('settings')->where('key', self::SETTING)->first();
        if (! $setting) {
            return;
        }

        $map = json_decode((string) $setting->value, true);
        if (! is_array($map) || ($map['vice_president'] ?? null) !== 'system_admin') {
            return;
        }

        unset($map['vice_president']);
        DB::table('settings')->where('key', self::SETTING)->update([
            'value' => json_encode($map, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
