<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionCacheAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $transactions = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_permission_cache_keys_are_forgotten_only_after_commit(): void
    {
        $user = User::factory()->create();
        $userKey = "auth:permissions:{$user->id}";
        $roleKey = "auth:role_perms:{$user->role_id}";
        Cache::put($userKey, ['old'], 300);
        Cache::put($roleKey, ['old'], 300);

        DB::transaction(function () use ($user, $userKey, $roleKey): void {
            $user->flushPermissionsCache();

            self::assertTrue(Cache::has($userKey));
            self::assertTrue(Cache::has($roleKey));
        });

        self::assertFalse(Cache::has($userKey));
        self::assertFalse(Cache::has($roleKey));
    }
}
