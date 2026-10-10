<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RetireJournalSelfPostOverrideMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_permission_and_setting_are_renamed_and_restored_on_rollback(): void
    {
        $this->assertDatabaseMissing('permissions', ['slug' => 'accounting.journal.self_post_override']);
        $this->assertDatabaseMissing('permissions', ['slug' => 'accounting.journal.self_post_override.retired']);

        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'Self-Post Journal Entries (override)',
            'slug' => 'accounting.journal.self_post_override',
            'module' => 'accounting',
            'description' => 'Legacy permission before retirement.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $settingId = DB::table('settings')->where('key', 'accounting.je_self_post_limit.retired')->value('id');
        $this->assertNotNull($settingId);
        DB::table('settings')->where('id', $settingId)->update(['key' => 'accounting.je_self_post_limit']);

        $migration = require database_path('migrations/2026_10_02_000000_retire_journal_self_post_override.php');
        $migration->up();

        $this->assertDatabaseHas('permissions', [
            'id' => $permissionId,
            'slug' => 'accounting.journal.self_post_override.retired',
        ]);
        $this->assertDatabaseMissing('permissions', ['slug' => 'accounting.journal.self_post_override']);
        $this->assertDatabaseHas('settings', [
            'id' => $settingId,
            'key' => 'accounting.je_self_post_limit.retired',
        ]);
        $this->assertDatabaseMissing('settings', ['key' => 'accounting.je_self_post_limit']);

        $migration->down();

        $this->assertDatabaseHas('permissions', [
            'id' => $permissionId,
            'slug' => 'accounting.journal.self_post_override',
        ]);
        $this->assertDatabaseMissing('permissions', ['slug' => 'accounting.journal.self_post_override.retired']);
        $this->assertDatabaseHas('settings', [
            'id' => $settingId,
            'key' => 'accounting.je_self_post_limit',
        ]);
        $this->assertDatabaseMissing('settings', ['key' => 'accounting.je_self_post_limit.retired']);
    }
}
