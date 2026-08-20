<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Notifications\CriticalAlertEmail;
use App\Common\Services\AlertEngineService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Notifications\DailyProductionSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Two email fanouts filtered their recipients with
 *
 *     $users->filter(static fn (User $user): bool => filter_var($user->email, FILTER_VALIDATE_EMAIL))
 *
 * `filter_var` with `FILTER_VALIDATE_EMAIL` returns **the address** on success
 * and `false` only on failure. Under `declare(strict_types=1)` a closure typed
 * `: bool` that returns a string is a `TypeError` — so these paths threw for
 * every recipient whose address was VALID, and worked only when every address
 * was malformed. The happy path was the broken one, which is why nothing caught
 * it: an install with no valid recipient email exercises the code fine.
 *
 * The two sites failed differently, and the difference is the point:
 *
 *   - `SendDailyProductionSummary` had no surrounding catch, so the command
 *     aborted. `schedule:run-fail-fast` recorded a failed task and the
 *     `scheduler:health` Docker healthcheck turned the container **unhealthy** —
 *     loud, and how this was found.
 *   - `AlertEngineService::emailCritical()` wraps the filter in
 *     `catch (\Throwable)`, which swallowed the `TypeError` and reported it as
 *     "The email provider rejected or could not deliver the critical alert."
 *     The operator was told SMTP failed. Worse, `notified_email_at` is set only
 *     after a successful `Notification::send`, so every `alerts:run` tick — every
 *     15 minutes — retried, threw again, and produced another in-app fallback.
 *     Critical alert email was never delivered and never diagnosable from its
 *     own error message.
 *
 * These tests pin the behaviour rather than the expression: a valid-addressed
 * recipient must actually be sent to. Asserting `NotSentTo` for the malformed
 * user keeps the filter honest, so "fixing" the type error by dropping the
 * filter entirely would fail here.
 */
class ValidEmailRecipientFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase does not seed, and both paths select recipients by role
        // slug, so `roles` must exist before any user can be created.
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug, string $email): User
    {
        return User::factory()->create([
            'role_id'   => Role::query()->where('slug', $slug)->value('id'),
            'email'     => $email,
            'is_active' => true,
        ]);
    }

    /**
     * A valid address is exactly the input that used to throw, so this is the
     * regression. `production_manager` is the seeded role in migration 0375's
     * `production.summary.notification_roles`.
     */
    public function test_the_daily_production_summary_reaches_a_recipient_with_a_valid_address(): void
    {
        Notification::fake();
        $manager = $this->userWithRole('production_manager', 'plant.manager@ogami.test');

        $this->artisan('production:send-daily-summary')->assertSuccessful();

        Notification::assertSentTo($manager, DailyProductionSummary::class);
    }

    /**
     * The filter must still exclude a malformed address. Without this, deleting
     * the filter would satisfy the test above and silently hand a broken address
     * to the mailer.
     */
    public function test_the_daily_production_summary_skips_a_malformed_address_but_still_sends_to_the_valid_one(): void
    {
        Notification::fake();
        $good = $this->userWithRole('production_manager', 'good.manager@ogami.test');
        $bad  = $this->userWithRole('production_manager', 'not-an-email');

        $this->artisan('production:send-daily-summary')->assertSuccessful();

        Notification::assertSentTo($good, DailyProductionSummary::class);
        Notification::assertNotSentTo($bad, DailyProductionSummary::class);
    }

    /**
     * The alert-engine half, driven through the public `raise()` rather than a
     * test-only seam: `raise()` calls `emailCritical()` for any Critical alert.
     * `emailCritical()` swallows throws, so a failure is invisible in the return
     * value — `notified_email_at` is the only observable separating "delivered"
     * from "caught and misreported", because it is stamped only after
     * `Notification::send` returns.
     */
    public function test_a_critical_alert_emails_a_valid_recipient_and_stamps_notified_email_at(): void
    {
        Notification::fake();
        $finance = $this->userWithRole('finance_officer', 'finance@ogami.test');

        DB::table('settings')->updateOrInsert(
            ['key' => 'alerts.critical.notification_roles'],
            ['value' => json_encode([AlertType::ArOverdue60->value => ['finance_officer']])],
        );

        $alert = app(AlertEngineService::class)->raise(
            AlertType::ArOverdue60,
            AlertSeverity::Critical,
            'AR overdue past 60 days',
            'A receivable has aged past 60 days.',
        );

        $this->assertNotNull(
            $alert->fresh()->notified_email_at,
            'notified_email_at is unset, so the send did not complete — the TypeError was caught and reported as a provider failure.',
        );
        Notification::assertSentTo($finance, CriticalAlertEmail::class);
    }
}
