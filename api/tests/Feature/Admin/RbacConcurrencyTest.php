<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Models\AuditLog;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Admin\Services\UserPermissionOverrideService;
use App\Modules\Admin\Services\RoleService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PostgreSQL-only integration coverage for the stable-parent-row locks used by
 * RBAC writes. The two child processes use separate PDO connections after the
 * fork, so the test exercises real row-lock waiting rather than a mock.
 */
class RbacConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const CONCURRENCY_CONNECTION = 'rbac_concurrency';

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    /** @var array<int, int> */
    private array $fixtureUserIds = [];

    /** @var array<int, int> */
    private array $fixtureRoleIds = [];

    /** @var array<int, int> */
    private array $fixturePermissionIds = [];

    private string $originalDefaultConnection = 'pgsql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = DB::getDefaultConnection();
        $this->configureConcurrencyConnection();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupConcurrencyFixtures();
        } finally {
            DB::purge(self::CONCURRENCY_CONNECTION);
            DB::setDefaultConnection($this->originalDefaultConnection);
            parent::tearDown();
        }
    }

    /**
     * `$connectionsToTransact = []` turns RefreshDatabase's per-test
     * transaction OFF for this class, because the forked worker uses a second
     * PDO connection and could not otherwise see the fixtures. Everything this
     * class writes is therefore COMMITTED and outlives the test.
     *
     * cleanupConcurrencyFixtures() cannot fully undo that: the `users` rows it
     * creates are referenced by `audit_logs`, which carries an append-only
     * trigger, so deleting them raises "Audit logs are immutable." An ACTIVE
     * `system_admin` ("concurrency-admin-…@test.local") consequently survives
     * for the rest of the PHPUnit process.
     *
     * That row is not inert. This class sorts before every `User*` class in
     * tests/Feature/Admin, so any later test whose premise is "no active system
     * administrator" or "no eligible automation actor" was silently disarmed by
     * it — the guard under test simply never fired, and the test passed for the
     * wrong reason. It has done this twice: UserAdministrationHardeningTest
     * carries a workaround for it, and it produced a false failure in the
     * Assets module.
     *
     * Resetting RefreshDatabaseState::$migrated makes the NEXT RefreshDatabase
     * class run `migrate:fresh` again, which drops the committed rows at the
     * schema level instead of fighting the audit trigger. Same remedy as
     * AccountingPeriodPostingConcurrencyTest.
     */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }

    public function test_concurrent_first_override_sets_have_one_create_and_one_update(): void
    {
        $this->requirePostgresProcessSupport();

        $adminRole = Role::query()->firstOrCreate(
            ['slug' => 'system_admin'],
            ['name' => 'System Administrator', 'is_system' => true],
        );
        $targetRole = Role::query()->create([
            'name' => 'Concurrency Employee',
            'slug' => 'concurrency_employee_'.uniqid(),
            'is_system' => false,
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'hr.employees.view'],
            ['name' => 'View employees', 'module' => 'hr'],
        );
        $this->fixtureRoleIds = [$targetRole->id];
        if ($adminRole->wasRecentlyCreated) {
            $this->fixtureRoleIds[] = $adminRole->id;
        }
        if ($permission->wasRecentlyCreated) {
            $this->fixturePermissionIds[] = $permission->id;
        }

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'concurrency-admin-'.uniqid().'@test.local',
        ]);
        $target = User::factory()->create([
            'role_id' => $targetRole->id,
            'email' => 'concurrency-target-'.uniqid().'@test.local',
        ]);
        $this->fixtureUserIds = [$admin->id, $target->id];

        $this->prepareForFork();
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork the concurrency worker.');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->reconnectAfterFork();
            fwrite($childSocket, "ready\n");
            fgets($childSocket);

            try {
                $service = app(UserPermissionOverrideService::class);
                $service->set(
                    User::findOrFail($target->id),
                    User::findOrFail($admin->id),
                    'hr.employees.view',
                    PermissionOverrideType::Revoke,
                    'Child concurrent override.',
                );
                fwrite($childSocket, "ok\n");
            } catch (\Throwable $e) {
                fwrite($childSocket, "error:".$e->getMessage()."\n");
            } finally {
                fclose($childSocket);
            }
            exit(0);
        }

        fclose($childSocket);
        $this->reconnectAfterFork();
        stream_set_timeout($parentSocket, 30);
        $this->assertSame('ready', trim((string) fgets($parentSocket)));
        fwrite($parentSocket, "go\n");

        app(UserPermissionOverrideService::class)->set(
            $target,
            $admin,
            'hr.employees.view',
            PermissionOverrideType::Grant,
            'Parent concurrent override.',
        );

        $childResult = trim((string) fgets($parentSocket));
        pcntl_waitpid($pid, $status);
        fclose($parentSocket);

        $this->assertSame('ok', $childResult);
        $override = UserPermissionOverride::withTrashed()
            ->where('user_id', $target->id)
            ->firstOrFail();
        $this->assertSame(1, UserPermissionOverride::withTrashed()
            ->where('user_id', $target->id)
            ->where('permission_id', $override->permission_id)
            ->count());

        $audits = AuditLog::query()
            ->where('model_type', $override->getMorphClass())
            ->where('model_id', $override->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $audits);
        $this->assertSame(1, $audits->where('action', 'created')->count());
        $this->assertSame(1, $audits->where('action', 'updated')->count());
    }

    public function test_concurrent_role_permission_syncs_audit_the_serialized_baseline(): void
    {
        $this->requirePostgresProcessSupport();

        $permissionView = Permission::query()->firstOrCreate(
            ['slug' => 'hr.employees.view'],
            ['name' => 'View employees', 'module' => 'hr'],
        );
        $permissionCreate = Permission::query()->firstOrCreate(
            ['slug' => 'hr.employees.create'],
            ['name' => 'Create employees', 'module' => 'hr'],
        );
        if ($permissionView->wasRecentlyCreated) {
            $this->fixturePermissionIds[] = $permissionView->id;
        }
        if ($permissionCreate->wasRecentlyCreated) {
            $this->fixturePermissionIds[] = $permissionCreate->id;
        }

        $role = Role::create([
            'name' => 'Concurrent Role',
            'slug' => 'concurrent_role_'.uniqid(),
            'description' => 'Concurrency fixture',
            'is_system' => false,
        ]);
        $this->fixtureRoleIds = [$role->id];

        $this->prepareForFork();
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork the concurrency worker.');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->reconnectAfterFork();
            fwrite($childSocket, "ready\n");
            fgets($childSocket);

            try {
                app(RoleService::class)->syncPermissions(
                    Role::findOrFail($role->id),
                    ['hr.employees.view'],
                );
                fwrite($childSocket, "ok\n");
            } catch (\Throwable $e) {
                fwrite($childSocket, "error:".$e->getMessage()."\n");
            } finally {
                fclose($childSocket);
            }
            exit(0);
        }

        fclose($childSocket);
        $this->reconnectAfterFork();
        stream_set_timeout($parentSocket, 30);
        $this->assertSame('ready', trim((string) fgets($parentSocket)));
        fwrite($parentSocket, "go\n");

        app(RoleService::class)->syncPermissions(
            $role,
            ['hr.employees.create'],
        );

        $childResult = trim((string) fgets($parentSocket));
        pcntl_waitpid($pid, $status);
        fclose($parentSocket);

        $this->assertSame('ok', $childResult);
        $audits = AuditLog::query()
            ->where('model_type', $role->getMorphClass())
            ->where('model_id', $role->id)
            ->where('action', 'permissions_synced')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $audits);
        $this->assertSame(1, $audits->filter(fn (AuditLog $audit): bool => ($audit->old_values['permissions'] ?? []) === [])->count());
        $this->assertSame(1, $audits->filter(fn (AuditLog $audit): bool => ($audit->old_values['permissions'] ?? []) !== [])->count());
        $this->assertCount(1, $role->fresh()->permissions);
        $this->assertContains($role->fresh()->permissions->first()->slug, ['hr.employees.view', 'hr.employees.create']);
    }

    private function requirePostgresProcessSupport(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for two-connection concurrency coverage.');
        }
    }

    private function configureConcurrencyConnection(): void
    {
        config([
            'database.connections.'.self::CONCURRENCY_CONNECTION => config('database.connections.pgsql'),
        ]);
        DB::disconnect('pgsql');
        DB::purge('pgsql');
        DB::purge(self::CONCURRENCY_CONNECTION);
        DB::setDefaultConnection(self::CONCURRENCY_CONNECTION);
    }

    private function prepareForFork(): void
    {
        // PDO/libpq connections must not cross fork(). Both workers reconnect
        // after the fork so closing one worker cannot terminate the other's
        // inherited PostgreSQL session.
        DB::disconnect(self::CONCURRENCY_CONNECTION);
        DB::purge(self::CONCURRENCY_CONNECTION);
    }

    private function reconnectAfterFork(): void
    {
        DB::purge(self::CONCURRENCY_CONNECTION);
        DB::reconnect(self::CONCURRENCY_CONNECTION);
    }

    private function cleanupConcurrencyFixtures(): void
    {
        if ($this->fixtureUserIds === [] && $this->fixtureRoleIds === [] && $this->fixturePermissionIds === []) {
            return;
        }

        $connection = DB::connection(self::CONCURRENCY_CONNECTION);

        if ($this->fixtureUserIds !== []) {
            $connection->table('notifications')
                ->where('notifiable_type', User::class)
                ->whereIn('notifiable_id', $this->fixtureUserIds)
                ->delete();
            $connection->table('user_permission_overrides')
                ->whereIn('user_id', $this->fixtureUserIds)
                ->delete();
        }

        if ($this->fixtureRoleIds !== []) {
            $connection->table('role_permissions')->whereIn('role_id', $this->fixtureRoleIds)->delete();
        }
    }
}
