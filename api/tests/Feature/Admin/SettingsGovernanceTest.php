<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Governance guarantees for the system-settings surface: unknown keys are
 * refused, a value cannot change JSON class, and every edit leaves an immutable
 * before/after record whose redaction is narrow enough to stay useful.
 */
class SettingsGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_an_unknown_setting_key_is_refused(): void
    {
        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/attacker.injected_flag', ['value' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');

        $this->assertDatabaseMissing('settings', ['key' => 'attacker.injected_flag']);
    }

    /**
     * json_encode(0.0) emits "0", which decodes as an int. An int/float split in
     * the stored-type guard therefore froze every whole-number decimal setting
     * to integers, making the loan rate columns permanently uneditable.
     */
    public function test_a_decimal_value_is_accepted_for_a_whole_number_numeric_setting(): void
    {
        $this->seedSetting('loans.company_loan.annual_interest_rate', 0.0);
        $this->assertSame('0', DB::table('settings')
            ->where('key', 'loans.company_loan.annual_interest_rate')->value('value'));

        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/loans.company_loan.annual_interest_rate', ['value' => 0.05])
            ->assertOk();

        $this->assertSame(0.05, json_decode((string) DB::table('settings')
            ->where('key', 'loans.company_loan.annual_interest_rate')->value('value'), true));
    }

    public function test_a_multiplier_stored_as_one_accepts_a_fractional_value(): void
    {
        $this->seedSetting('loans.company_loan.max_salary_multiplier', 1.0);

        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/loans.company_loan.max_salary_multiplier', ['value' => 1.5])
            ->assertOk();

        $this->assertSame(1.5, json_decode((string) DB::table('settings')
            ->where('key', 'loans.company_loan.max_salary_multiplier')->value('value'), true));
    }

    public function test_a_value_cannot_change_its_stored_json_class(): void
    {
        $this->seedSetting('company.name', 'Philippine Ogami Corporation');

        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/company.name', ['value' => 42])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_an_integer_ruled_setting_still_refuses_a_decimal(): void
    {
        $this->seedSetting('security.max_login_attempts', 5);

        // The stored-type guard now treats every JSON number alike; the per-key
        // `integer` rule is what keeps this one whole.
        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/security.max_login_attempts', ['value' => 5.5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_an_update_writes_an_immutable_before_and_after_audit_row(): void
    {
        $this->seedSetting('security.max_login_attempts', 5);
        $admin = $this->systemAdmin();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/settings/security.max_login_attempts', [
                'value' => 7,
                'reason' => 'Tightened after the August lockout review.',
            ])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'settings.updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('security.max_login_attempts', $log->old_values['key']);
        $this->assertSame(5, $log->old_values['value']);
        $this->assertSame(7, $log->new_values['value']);
        $this->assertSame('Tightened after the August lockout review.', $log->reason);

        $this->expectException(\RuntimeException::class);
        $log->update(['action' => 'settings.tampered']);
    }

    /**
     * The previous substring regex matched "accoun(tin)g", "forecas(tin)g" and
     * "ra(tin)g", plus `bank`/`routing` inside GL account codes — 69 of 420
     * seeded keys — so the accounting settings history read `***`.
     */
    public function test_a_non_secret_setting_value_is_not_redacted(): void
    {
        $this->seedSetting('accounting.accounts.salary_expense_code', '5050');

        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/accounting.accounts.salary_expense_code', ['value' => '5051'])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'settings.updated')->latest('id')->firstOrFail();
        $this->assertSame('5050', $log->old_values['value']);
        $this->assertSame('5051', $log->new_values['value']);
    }

    public function test_a_security_policy_value_stays_visible_in_the_audit_trail(): void
    {
        $this->seedSetting('security.password_min_length', 8);

        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/security.password_min_length', ['value' => 12])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'settings.updated')->latest('id')->firstOrFail();
        $this->assertSame(8, $log->old_values['value']);
        $this->assertSame(12, $log->new_values['value']);
    }

    public function test_an_identifier_setting_value_is_redacted(): void
    {
        $this->seedSetting('company.tin', '123-456-789-000');

        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/company.tin', ['value' => '999-888-777-000'])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'settings.updated')->latest('id')->firstOrFail();
        $this->assertSame('***', $log->old_values['value']);
        $this->assertSame('***', $log->new_values['value']);
    }

    public function test_a_compound_credential_segment_is_redacted(): void
    {
        $this->seedSetting('mail.smtp_password', 'hunter2');

        $this->actingAs($this->systemAdmin())
            ->putJson('/api/v1/admin/settings/mail.smtp_password', ['value' => 'hunter3'])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'settings.updated')->latest('id')->firstOrFail();
        $this->assertSame('***', $log->old_values['value']);
        $this->assertSame('***', $log->new_values['value']);
    }

    public function test_settings_management_requires_the_settings_permission(): void
    {
        $this->seedSetting('security.max_login_attempts', 5);
        $role = Role::create([
            'name' => 'Settings test role',
            'slug' => 'settings_t_'.substr(uniqid(), -5),
            'description' => 'Test role',
            'is_system' => false,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'user+'.uniqid().'@test.local',
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/admin/settings/security.max_login_attempts', ['value' => 7])
            ->assertForbidden();
    }

    private function seedSetting(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value),
                'group' => explode('.', $key)[0],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function systemAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'admin+'.uniqid().'@test.local',
        ]);
    }
}
