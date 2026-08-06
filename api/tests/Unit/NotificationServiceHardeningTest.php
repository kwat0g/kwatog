<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Mail\UserNotificationMail;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Covers the three guarantees NotificationService is responsible for:
 * transactional safety, bounded query cost, and a well-formed envelope.
 */
class NotificationServiceHardeningTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
    }

    private function enableEmailFor(User $user, string $type): void
    {
        DB::table('notification_preferences')->insert([
            'user_id'           => $user->id,
            'notification_type' => $type,
            'channel'           => 'email',
            'enabled'           => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /* ─── Transactional safety ─────────────────────────────────────────── */

    public function test_rollback_suppresses_the_broadcast_and_the_email(): void
    {
        $user = User::factory()->create(['email' => 'rollback@ogami.test']);
        $this->enableEmailFor($user, 'test.rollback');

        Event::fake([UserNotificationCreated::class]);
        Mail::fake();

        try {
            DB::transaction(function () use ($user): void {
                $this->service->send($user, 'test.rollback', [
                    'title'   => 'Should never arrive',
                    'message' => 'The caller is about to fail.',
                ]);

                throw new \RuntimeException('caller failed after notifying');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('notifications', ['type' => 'test.rollback']);
        Event::assertNotDispatched(UserNotificationCreated::class);
        Mail::assertNothingQueued();
    }

    public function test_commit_still_delivers_the_broadcast_and_the_email(): void
    {
        $user = User::factory()->create(['email' => 'commit@ogami.test']);
        $this->enableEmailFor($user, 'test.commit');

        Event::fake([UserNotificationCreated::class]);
        Mail::fake();

        DB::transaction(function () use ($user): void {
            $this->service->send($user, 'test.commit', [
                'title'   => 'Arrives',
                'message' => 'The caller commits.',
            ]);

            // Still inside the transaction: neither side effect may have run.
            Event::assertNotDispatched(UserNotificationCreated::class);
            Mail::assertNothingQueued();
        });

        $this->assertDatabaseHas('notifications', ['type' => 'test.commit']);
        Event::assertDispatched(UserNotificationCreated::class, 1);
        Mail::assertQueued(UserNotificationMail::class, 1);
    }

    public function test_send_outside_a_transaction_dispatches_immediately(): void
    {
        $user = User::factory()->create();
        Event::fake([UserNotificationCreated::class]);

        $this->service->send($user, 'test.no_txn', ['title' => 'Inline', 'message' => 'x']);

        Event::assertDispatched(UserNotificationCreated::class, 1);
    }

    /* ─── Bounded cost ─────────────────────────────────────────────────── */

    public function test_query_count_does_not_scale_with_recipient_count(): void
    {
        $users = User::factory()->count(25)->create();
        Event::fake();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service->send($users, 'test.fanout', ['title' => 'Fan out', 'message' => 'x']);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // One preference lookup + one bulk insert. The old implementation ran
        // three queries per recipient (in_app pref, email pref, insert) — 75.
        $this->assertLessThanOrEqual(3, count($queries), sprintf(
            "Expected a constant number of queries, got %d:\n%s",
            count($queries),
            implode("\n", array_column($queries, 'query')),
        ));

        $this->assertSame(25, DB::table('notifications')->where('type', 'test.fanout')->count());
    }

    public function test_duplicate_recipients_receive_one_notification(): void
    {
        // A user holding two notified roles appears twice in a merged audience.
        $user = User::factory()->create();
        Event::fake();

        $this->service->send([$user, $user], 'test.dupe', ['title' => 'Once', 'message' => 'x']);

        $this->assertSame(1, DB::table('notifications')->where('type', 'test.dupe')->count());
    }

    public function test_disabled_recipient_is_skipped_without_blocking_the_others(): void
    {
        $muted   = User::factory()->create();
        $wanting = User::factory()->create();

        DB::table('notification_preferences')->insert([
            'user_id'           => $muted->id,
            'notification_type' => 'test.mixed',
            'channel'           => 'in_app',
            'enabled'           => false,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        Event::fake();

        $this->service->send([$muted, $wanting], 'test.mixed', ['title' => 'Mixed', 'message' => 'x']);

        $this->assertDatabaseMissing('notifications', [
            'type'          => 'test.mixed',
            'notifiable_id' => $muted->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'type'          => 'test.mixed',
            'notifiable_id' => $wanting->id,
        ]);
    }

    public function test_empty_audience_is_a_no_op(): void
    {
        Event::fake();

        $this->service->send([], 'test.empty', ['title' => 'Nobody', 'message' => 'x']);

        $this->assertSame(0, DB::table('notifications')->where('type', 'test.empty')->count());
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    /* ─── Envelope integrity ───────────────────────────────────────────── */

    public function test_missing_title_falls_back_to_a_readable_label(): void
    {
        $user = User::factory()->create();
        Event::fake();

        $this->service->send($user, 'quality.inspection_failed', ['message' => 'No title supplied']);

        $data = json_decode((string) DB::table('notifications')->value('data'), true);

        $this->assertSame('Quality Inspection Failed', $data['title']);
    }

    public function test_oversized_message_is_clamped(): void
    {
        $user = User::factory()->create();
        Event::fake();

        $this->service->send($user, 'test.big', [
            'title'   => str_repeat('T', 400),
            'message' => str_repeat('M', 5000),
        ]);

        $data = json_decode((string) DB::table('notifications')->value('data'), true);

        $this->assertLessThanOrEqual(256, mb_strlen($data['title']));
        $this->assertLessThanOrEqual(2001, mb_strlen($data['message']));
    }

    public function test_non_string_message_does_not_break_the_row(): void
    {
        $user = User::factory()->create();
        Event::fake();

        $this->service->send($user, 'test.badtypes', [
            'title'   => 'Fine',
            'message' => ['unexpected' => 'array'],
            'link_to' => 12345,
        ]);

        $data = json_decode((string) DB::table('notifications')->value('data'), true);

        $this->assertSame('', $data['message']);
        $this->assertArrayNotHasKey('link_to', $data, 'A non-string link would break navigation.');
    }

    public function test_caller_supplied_extra_fields_survive(): void
    {
        $user = User::factory()->create();
        Event::fake();

        $this->service->send($user, 'test.extra', [
            'title'       => 'Keeps context',
            'message'     => 'x',
            'link_to'     => '/purchasing/purchase-orders/abc',
            'entity_type' => 'purchase_order',
            'entity_id'   => 'abc',
            'po_number'   => 'PO-202608-0001',
        ]);

        $data = json_decode((string) DB::table('notifications')->value('data'), true);

        $this->assertSame('PO-202608-0001', $data['po_number']);
        $this->assertSame('purchase_order', $data['entity_type']);
        $this->assertSame('/purchasing/purchase-orders/abc', $data['link_to']);
    }

    public function test_email_stays_opt_in(): void
    {
        $optedIn  = User::factory()->create(['email' => 'yes@ogami.test']);
        $default  = User::factory()->create(['email' => 'no@ogami.test']);
        $this->enableEmailFor($optedIn, 'test.optin');

        Event::fake();
        Mail::fake();

        $this->service->send([$optedIn, $default], 'test.optin', ['title' => 'Opt in', 'message' => 'x']);

        Mail::assertQueued(UserNotificationMail::class, 1);

        // Both still get the in-app row; only the mail channel differs.
        $this->assertSame(2, DB::table('notifications')->where('type', 'test.optin')->count());
    }
}
