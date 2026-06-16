<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * OGAMI-002 — maker-checker / segregation of duties on journal entry posting.
 */
class JournalEntryMakerCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        // Default behaviour: maker !== checker always required.
        config()->set('accounting.je_self_post_limit', '0');
    }

    /** Non-admin user so hasPermission() does not short-circuit the override. */
    private function financeUser(): User
    {
        $roleId = Role::query()->where('slug', 'finance_officer')->value('id');
        return User::create([
            'name' => 'Fin '.substr(uniqid(), -5),
            'email' => 'f_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
    }

    private function acct(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    private function draftJe(JournalEntryService $svc, User $by)
    {
        return $svc->create([
            'date' => '2026-04-15',
            'description' => 'Maker-checker draft',
            'lines' => [
                ['account_id' => $this->acct('1010'), 'debit' => '5000.00', 'credit' => '0'],
                ['account_id' => $this->acct('3010'), 'debit' => '0',       'credit' => '5000.00'],
            ],
        ], $by);
    }

    public function test_creator_cannot_self_post(): void
    {
        $svc = app(JournalEntryService::class);
        $maker = $this->financeUser();

        $je = $this->draftJe($svc, $maker);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('segregation of duties');
        $svc->post($je, $maker);
    }

    public function test_different_user_can_post(): void
    {
        $svc = app(JournalEntryService::class);
        $maker = $this->financeUser();
        $checker = $this->financeUser();

        $je = $this->draftJe($svc, $maker);
        $posted = $svc->post($je, $checker);

        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
        $this->assertSame((int) $checker->id, (int) $posted->posted_by);
    }

    public function test_override_permission_allows_self_post(): void
    {
        $svc = app(JournalEntryService::class);
        $maker = $this->financeUser();

        // Permission may not be seeded yet (follow-up for orchestrator) — ensure it exists.
        $perm = Permission::firstOrCreate(
            ['slug' => 'accounting.journal.self_post_override'],
            ['name' => 'Self-Post Journal Entries (override)', 'module' => 'accounting']
        );
        UserPermissionOverride::create([
            'user_id'       => $maker->id,
            'permission_id' => $perm->id,
            'type'          => 'grant',
            'granted_by'    => $maker->id,
            'reason'        => 'Test override grant',
        ]);
        $maker->flushPermissionsCache();

        $je = $this->draftJe($svc, $maker);
        $posted = $svc->post($je, $maker->fresh());

        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
    }

    public function test_self_post_allowed_below_configured_limit(): void
    {
        // Below-threshold self-post escape hatch.
        config()->set('accounting.je_self_post_limit', '10000.00');
        $svc = app(JournalEntryService::class);
        $maker = $this->financeUser();

        // Total is 5000.00 < 10000.00 → permitted.
        $je = $this->draftJe($svc, $maker);
        $posted = $svc->post($je, $maker);

        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
    }
}
