<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Mail\QueuedMailable;
use App\Common\Mail\UserNotificationMail;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
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
        $this->service = new NotificationService;
    }

    private function enableEmailFor(User $user, string $type): void
    {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id,
            'notification_type' => $type,
            'channel' => 'email',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_permission_audience_honors_active_user_overrides(): void
    {
        $permission = Permission::firstOrCreate(
            ['slug' => 'test.notification.permission'],
            ['name' => 'Test notification permission', 'module' => 'test'],
        );
        $role = Role::create(['name' => 'Notification permission role', 'slug' => 'test_notification_permission']);
        $role->permissions()->attach($permission->id);
        $roleUser = User::factory()->create(['role_id' => $role->id]);
        $grant = User::factory()->create();
        $revoked = User::factory()->create(['role_id' => $role->id]);
        $inactiveGrant = User::factory()->create(['is_active' => false]);

        foreach ([[$grant, 'grant'], [$revoked, 'revoke'], [$inactiveGrant, 'grant']] as [$user, $type]) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $user->id,
                'permission_id' => $permission->id,
                'type' => $type,
                'granted_by' => $roleUser->id,
                'reason' => 'Notification audience test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertEqualsCanonicalizing(
            [$roleUser->id, $grant->id],
            $this->service->usersWithPermission('test.notification.permission')->modelKeys(),
        );
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
                    'title' => 'Should never arrive',
                    'message' => 'The caller is about to fail.',
                ]);

                throw new RuntimeException('caller failed after notifying');
            });
        } catch (RuntimeException) {
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
                'title' => 'Arrives',
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

    public function test_realtime_broadcast_failure_does_not_retry_the_durable_inbox_write(): void
    {
        $user = User::factory()->create();
        Mail::fake();
        Event::listen(UserNotificationCreated::class, static function (): void {
            throw new RuntimeException('realtime broker unavailable');
        });

        $this->service->send($user, 'test.broadcast_failure', [
            'title' => 'Durable inbox',
            'message' => 'The realtime channel is optional.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'test.broadcast_failure',
        ]);
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

    public function test_recipient_reasons_are_stored_per_user_without_changing_shared_message(): void
    {
        $currentApprover = User::factory()->create();
        $delegate = User::factory()->create();
        Event::fake([UserNotificationCreated::class]);

        $this->service->send(
            [$currentApprover, $delegate],
            'test.recipient_reason',
            ['title' => 'Needs approval', 'message' => 'One action.'],
            'approval:1',
            [
                $currentApprover->id => 'current_approval_step',
                $delegate->id => 'active_approval_delegate',
            ],
        );

        $currentData = json_decode((string) DB::table('notifications')->where('notifiable_id', $currentApprover->id)->value('data'), true);
        $delegateData = json_decode((string) DB::table('notifications')->where('notifiable_id', $delegate->id)->value('data'), true);

        $this->assertSame('current_approval_step', $currentData['recipient_reason']);
        $this->assertSame('active_approval_delegate', $delegateData['recipient_reason']);
        $this->assertSame('Needs approval', $currentData['title']);
        $this->assertSame('Needs approval', $delegateData['title']);
    }

    public function test_disabled_recipient_is_skipped_without_blocking_the_others(): void
    {
        $muted = User::factory()->create();
        $wanting = User::factory()->create();

        DB::table('notification_preferences')->insert([
            'user_id' => $muted->id,
            'notification_type' => 'test.mixed',
            'channel' => 'in_app',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Event::fake();

        $this->service->send([$muted, $wanting], 'test.mixed', ['title' => 'Mixed', 'message' => 'x']);

        $this->assertDatabaseMissing('notifications', [
            'type' => 'test.mixed',
            'notifiable_id' => $muted->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => 'test.mixed',
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
            'title' => str_repeat('T', 400),
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
            'title' => 'Fine',
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
            'title' => 'Keeps context',
            'message' => 'x',
            'link_to' => '/purchasing/purchase-orders/abc',
            'entity_type' => 'purchase_order',
            'entity_id' => 'abc',
            'po_number' => 'PO-202608-0001',
        ]);

        $data = json_decode((string) DB::table('notifications')->value('data'), true);

        $this->assertSame('PO-202608-0001', $data['po_number']);
        $this->assertSame('purchase_order', $data['entity_type']);
        $this->assertSame('/purchasing/purchase-orders/abc', $data['link_to']);
        $this->assertSame(1, $data['schema_version']);
        $this->assertSame('test.extra', $data['event_type']);
        $this->assertSame('purchase_order', $data['subject_type']);
        $this->assertSame('abc', $data['subject_id']);
    }

    public function test_versioned_envelope_keeps_explicit_actor_and_drops_invalid_subject_metadata(): void
    {
        $user = User::factory()->create();
        Event::fake();

        $this->service->send($user, 'test.structured', [
            'title' => 'Structured event',
            'actor_id' => ' actor-hash ',
            'subject_type' => ['invalid'],
            'subject_id' => new \stdClass,
        ]);

        $data = json_decode((string) DB::table('notifications')->value('data'), true);

        $this->assertSame(1, $data['schema_version']);
        $this->assertSame('test.structured', $data['event_type']);
        $this->assertSame('actor-hash', $data['actor_id']);
        $this->assertArrayNotHasKey('subject_type', $data);
        $this->assertArrayNotHasKey('subject_id', $data);
    }

    public function test_email_stays_opt_in(): void
    {
        $optedIn = User::factory()->create(['email' => 'yes@ogami.test']);
        $default = User::factory()->create(['email' => 'no@ogami.test']);
        $this->enableEmailFor($optedIn, 'test.optin');

        Event::fake();
        Mail::fake();

        $this->service->send([$optedIn, $default], 'test.optin', ['title' => 'Opt in', 'message' => 'x']);

        Mail::assertQueued(UserNotificationMail::class, 1);

        // Both still get the in-app row; only the mail channel differs.
        $this->assertSame(2, DB::table('notifications')->where('type', 'test.optin')->count());
    }

    public function test_invalid_email_address_is_skipped_without_an_exception(): void
    {
        $user = User::factory()->create(['email' => 'not-an-email']);
        $this->enableEmailFor($user, 'test.invalid_email');

        Event::fake();
        Mail::fake();

        $this->service->send($user, 'test.invalid_email', ['title' => 'Skip me', 'message' => 'x']);

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'test.invalid_email',
        ]);
    }

    public function test_email_opt_in_is_independent_of_in_app_opt_out(): void
    {
        $user = User::factory()->create(['email' => 'email-only@ogami.test']);
        DB::table('notification_preferences')->insert([
            [
                'user_id' => $user->id,
                'notification_type' => 'test.email_only',
                'channel' => 'in_app',
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'notification_type' => 'test.email_only',
                'channel' => 'email',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Event::fake();
        Mail::fake();

        $this->service->send($user, 'test.email_only', [
            'title' => 'Email only',
            'message' => 'In-app is disabled independently.',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'test.email_only',
        ]);
        Mail::assertQueued(UserNotificationMail::class, 1);
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_email_skips_inactive_and_invalid_recipients_and_deduplicates_shared_mailboxes(): void
    {
        $first = User::factory()->create(['email' => 'shared@ogami.test']);
        $duplicate = User::factory()->create(['email' => 'shared-duplicate@ogami.test']);
        DB::table('users')->where('id', $duplicate->id)->update(['email' => 'SHARED@ogami.test']);
        $duplicate->refresh();
        $invalid = User::factory()->create(['email' => 'not-an-email']);
        $inactive = User::factory()->create(['email' => 'inactive@ogami.test', 'is_active' => false]);
        foreach ([$first, $duplicate, $invalid, $inactive] as $user) {
            $this->enableEmailFor($user, 'test.recipient_safety');
        }

        Event::fake();
        Mail::fake();
        $this->service->send([$first, $duplicate, $invalid, $inactive], 'test.recipient_safety', [
            'title' => 'Recipient safety',
            'message' => 'One message per valid mailbox.',
        ]);

        Mail::assertQueued(UserNotificationMail::class, 1);
        Mail::assertQueued(UserNotificationMail::class, function (UserNotificationMail $mail) use ($first, $duplicate): bool {
            self::assertCount(1, $mail->to);
            self::assertSame('shared@ogami.test', $mail->to[0]['address']);
            self::assertEqualsCanonicalizing([$first->id, $duplicate->id], $mail->recipientUserIds);

            return true;
        });
        $this->assertSame(3, DB::table('notifications')->where('type', 'test.recipient_safety')->count());
    }

    public function test_replayed_deduplicated_notification_reuses_delivery_key(): void
    {
        $user = User::factory()->create(['email' => 'replay@ogami.test']);
        $this->enableEmailFor($user, 'test.replay');
        Event::fake();
        Mail::fake();
        $data = ['title' => 'Replay-safe', 'message' => 'Same business event.'];

        $this->service->send($user, 'test.replay', $data, 'event:123');
        $this->service->send($user, 'test.replay', $data, 'event:123');

        $mailables = Mail::queued(UserNotificationMail::class);
        $this->assertCount(2, $mailables);
        $key = new \ReflectionProperty(QueuedMailable::class, 'deliveryKey');
        $this->assertNotNull($key->getValue($mailables[0]));
        $this->assertSame($key->getValue($mailables[0]), $key->getValue($mailables[1]));
        $this->assertSame(1, DB::table('notifications')->where('type', 'test.replay')->count());
    }

    public function test_in_app_fallback_never_queues_email_even_when_email_is_enabled(): void
    {
        $user = User::factory()->create(['email' => 'fallback@ogami.test']);
        $this->enableEmailFor($user, 'email.delivery_failed');

        Event::fake();
        Mail::fake();

        $this->service->sendInApp($user, 'email.delivery_failed', [
            'title' => 'Email delivery failed',
            'message' => 'Use an approved alternate channel.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'email.delivery_failed',
        ]);
        Mail::assertNothingQueued();
    }

    public function test_email_delivery_fallback_skips_empty_recipient_collections(): void
    {
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('sendInApp');

        (new EmailDeliveryFailureNotifier($notifications))->notify(
            collect(),
            'Critical alert',
            'No recipient has a usable address.',
        );
    }
}
