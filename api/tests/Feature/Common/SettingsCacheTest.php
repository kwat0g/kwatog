<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_setting_is_not_cached_before_a_migration_inserts_it(): void
    {
        $key = 'test.cache_late_insert';
        Cache::forget("settings:{$key}");
        $settings = app(SettingsService::class);

        $this->assertSame([], $settings->get($key, []));

        DB::table('settings')->insert([
            'key' => $key,
            'value' => json_encode(['ready' => true]),
            'group' => 'test',
            'label' => 'Cache test',
            'description' => 'Test setting inserted after its first read.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(['ready' => true], $settings->get($key, []));
    }
}
