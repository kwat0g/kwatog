<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Admin\Services\ApprovalDelegationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acting on ANOTHER user's approval delegation is now a named grant.
 *
 * Approval delegation is self-service by design: the routes carry no permission
 * middleware, because any authenticated user manages the delegations where they
 * are the delegator (Admin/routes.php). The exception — reading and revoking
 * somebody else's — was authorised by a literal `role?->slug === 'system_admin'`
 * compare in three places. Unlike the Leave module's redundant admin branches,
 * this one was NOT dead code: no permission sat beside it to fall through to, so
 * it was the only authority. Removing it needed a permission to exist first,
 * hence `admin.delegations.manage_any`.
 */
class ApprovalDelegationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'dlg+'.substr(uniqid(), -8).'@t.test',
            'is_active' => true,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Delegation Role '.substr(uniqid(), -6),
            'slug' => 'dlg-'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'email' => 'dlg+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    private function delegationFrom(User $delegator, User $delegate): ApprovalDelegation
    {
        return ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'is_active' => true,
        ]);
    }

    public function test_the_permission_is_seeded(): void
    {
        $this->assertDatabaseHas('permissions', [
            'slug' => 'admin.delegations.manage_any',
            'module' => 'admin',
        ]);
    }

    public function test_a_plain_user_lists_only_delegations_it_is_party_to(): void
    {
        $me = $this->userWithRole('employee');
        $other = $this->userWithRole('employee');
        $third = $this->userWithRole('employee');

        $mine = $this->delegationFrom($me, $other);
        $toMe = $this->delegationFrom($third, $me);
        $foreign = $this->delegationFrom($other, $third);

        $ids = app(ApprovalDelegationService::class)->list($me)->pluck('id');

        $this->assertTrue($ids->contains($mine->id), 'lost a delegation it granted');
        $this->assertTrue($ids->contains($toMe->id), 'lost a delegation it received');
        $this->assertFalse($ids->contains($foreign->id), 'read a delegation it is not party to');
    }

    public function test_system_admin_still_lists_every_delegation(): void
    {
        $a = $this->userWithRole('employee');
        $b = $this->userWithRole('employee');
        $foreign = $this->delegationFrom($a, $b);

        $ids = app(ApprovalDelegationService::class)
            ->list($this->userWithRole('system_admin'))
            ->pluck('id');

        $this->assertTrue($ids->contains($foreign->id));
    }

    /**
     * The property the refactor buys: a role nobody wrote code for gets the
     * override by holding the grant. Nothing in the service mentions its slug.
     */
    public function test_the_grant_confers_the_override_without_naming_a_role(): void
    {
        $a = $this->userWithRole('employee');
        $b = $this->userWithRole('employee');
        $foreign = $this->delegationFrom($a, $b);

        $auditor = $this->userWithPermissions(['admin.delegations.manage_any']);

        $ids = app(ApprovalDelegationService::class)->list($auditor)->pluck('id');

        $this->assertTrue($ids->contains($foreign->id));
    }

    public function test_a_plain_user_may_not_revoke_someone_elses_delegation(): void
    {
        $a = $this->userWithRole('employee');
        $b = $this->userWithRole('employee');
        $foreign = $this->delegationFrom($a, $b);

        $this->expectException(BusinessRuleException::class);

        app(ApprovalDelegationService::class)->revoke($foreign, $this->userWithRole('employee'));
    }

    public function test_the_delegator_may_revoke_its_own(): void
    {
        $a = $this->userWithRole('employee');
        $b = $this->userWithRole('employee');
        $own = $this->delegationFrom($a, $b);

        app(ApprovalDelegationService::class)->revoke($own, $a);

        $this->assertFalse($own->fresh()->is_active);
    }

    public function test_the_grant_holder_may_revoke_anothers(): void
    {
        $a = $this->userWithRole('employee');
        $b = $this->userWithRole('employee');
        $foreign = $this->delegationFrom($a, $b);

        app(ApprovalDelegationService::class)
            ->revoke($foreign, $this->userWithPermissions(['admin.delegations.manage_any']));

        $this->assertFalse($foreign->fresh()->is_active);
    }

    /**
     * Creating on someone else's behalf is the same authority: without the grant
     * the delegator silently falls back to the acting user, so a normal user can
     * only ever delegate its OWN approval authority.
     */
    public function test_without_the_grant_the_delegator_is_forced_to_the_actor(): void
    {
        $actor = $this->userWithRole('employee');
        $victim = $this->userWithRole('employee');
        $delegate = $this->userWithRole('employee');

        $created = app(ApprovalDelegationService::class)->create([
            'delegator_user_id' => $victim->hash_id,
            'delegate_user_id' => $delegate->hash_id,
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addWeek()->toDateTimeString(),
        ], $actor);

        $this->assertSame($actor->id, $created->delegator_user_id);
        $this->assertNotSame($victim->id, $created->delegator_user_id);
    }

    public function test_with_the_grant_the_delegator_may_be_someone_else(): void
    {
        $admin = $this->userWithPermissions(['admin.delegations.manage_any']);
        $delegator = $this->userWithRole('employee');
        $delegate = $this->userWithRole('employee');

        $created = app(ApprovalDelegationService::class)->create([
            'delegator_user_id' => $delegator->hash_id,
            'delegate_user_id' => $delegate->hash_id,
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addWeek()->toDateTimeString(),
        ], $admin);

        $this->assertSame($delegator->id, $created->delegator_user_id);
    }
}
