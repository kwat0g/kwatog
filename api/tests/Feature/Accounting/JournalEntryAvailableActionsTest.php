<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryAvailableActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    public function test_detail_actions_follow_viewer_permissions_state_and_segregation_of_duties(): void
    {
        $maker = $this->userWith(
            'accounting.journal.view',
            'accounting.journal.create',
            'accounting.journal.post',
            'accounting.journal.reverse',
        );
        $checker = $this->userWith(
            'accounting.journal.view',
            'accounting.journal.create',
            'accounting.journal.post',
            'accounting.journal.reverse',
        );
        $reader = $this->userWith('accounting.journal.view');
        $entry = $this->draftEntry($maker);

        $this->actingAs($checker)
            ->getJson("/api/v1/journal-entries/{$entry->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.can_edit', true)
            ->assertJsonPath('data.available_actions.can_delete', true)
            ->assertJsonPath('data.available_actions.can_restore', false)
            ->assertJsonPath('data.available_actions.can_post', true)
            ->assertJsonPath('data.available_actions.can_reverse', false);

        $this->actingAs($reader)
            ->getJson("/api/v1/journal-entries/{$entry->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.can_edit', false)
            ->assertJsonPath('data.available_actions.can_delete', false)
            ->assertJsonPath('data.available_actions.can_post', false)
            ->assertJsonPath('data.available_actions.can_reverse', false);

        $this->actingAs($maker)
            ->getJson("/api/v1/journal-entries/{$entry->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.can_post', false);

        $this->actingAs($checker)
            ->patchJson("/api/v1/journal-entries/{$entry->hash_id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.available_actions.can_post', false)
            ->assertJsonPath('data.available_actions.can_reverse', true);

        $this->actingAs($checker)
            ->patchJson("/api/v1/journal-entries/{$entry->hash_id}/post")
            ->assertStatus(409)
            ->assertJsonPath('code', 'journal_entry_state_conflict');

        $this->actingAs($maker)
            ->getJson("/api/v1/journal-entries/{$entry->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.can_reverse', false);
    }

    private function draftEntry(User $maker): JournalEntry
    {
        return app(JournalEntryService::class)->create([
            'date' => '2026-04-15',
            'description' => 'Action projection regression',
            'lines' => [
                ['account_id' => $this->accountHash('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->accountHash('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $maker);
    }

    private function accountHash(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    private function userWith(string ...$permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Journal Action Test '.uniqid(),
            'slug' => 'journal_action_test_'.uniqid(),
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
