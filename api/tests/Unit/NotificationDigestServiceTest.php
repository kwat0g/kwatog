<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Mail\NotificationDigestMail;
use App\Common\Services\NotificationDigestService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The digest used to load every unread notification for every user before
 * checking who had actually opted in, so its memory cost tracked total unread
 * volume rather than subscriber count. These tests pin the corrected ordering.
 */
class NotificationDigestServiceTest extends TestCase
{
    use RefreshDatabase;

    private function optIn(User $user, string $type = '*'): void
    {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id,
            'notification_type' => $type,
            'channel' => 'digest',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function notify(User $user, bool $read = false, string $title = 'Unread item'): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test.digest',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => $title, 'message' => 'body']),
            'read_at' => $read ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_opted_in_users_are_emailed(): void
    {
        $subscriber = User::factory()->create(['email' => 'sub@ogami.test']);
        $bystander = User::factory()->create(['email' => 'nope@ogami.test']);

        $this->optIn($subscriber);
        $this->notify($subscriber);
        $this->notify($bystander);

        Mail::fake();

        $result = (new NotificationDigestService)->run();

        Mail::assertQueued(NotificationDigestMail::class, 1);
        $this->assertSame(1, $result['emails_sent']);
        $this->assertSame(1, $result['users_evaluated'], 'Non-subscribers must not even be evaluated.');
    }

    public function test_no_subscribers_means_no_notification_rows_are_read(): void
    {
        $user = User::factory()->create(['email' => 'x@ogami.test']);
        $this->notify($user);

        Mail::fake();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = (new NotificationDigestService)->run();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        Mail::assertNothingQueued();
        $this->assertSame(0, $result['emails_sent']);

        // The whole run should be the single opt-in lookup. Previously it
        // selected the entire unread table before discovering nobody wanted it.
        $this->assertCount(1, $queries, sprintf(
            "Expected only the subscriber lookup, got:\n%s",
            implode("\n", array_column($queries, 'query')),
        ));
    }

    public function test_read_notifications_are_not_summarised(): void
    {
        $user = User::factory()->create(['email' => 'sub@ogami.test']);
        $this->optIn($user);
        $this->notify($user, read: true);

        Mail::fake();

        $result = (new NotificationDigestService)->run();

        Mail::assertNothingQueued();
        $this->assertSame(0, $result['emails_sent']);
    }

    public function test_digest_does_not_mark_anything_read(): void
    {
        $user = User::factory()->create(['email' => 'sub@ogami.test']);
        $this->optIn($user);
        $this->notify($user);

        Mail::fake();
        (new NotificationDigestService)->run();

        $this->assertSame(
            1,
            DB::table('notifications')->whereNull('read_at')->count(),
            'The digest is a reminder, not a mark-read action.',
        );
    }

    public function test_digest_contains_an_exact_unread_count_without_notification_details(): void
    {
        $user = User::factory()->create(['email' => 'sub@ogami.test']);
        $this->optIn($user);

        for ($i = 0; $i < 25; $i++) {
            $this->notify($user, title: "Private item {$i}");
        }

        Mail::fake();

        $result = (new NotificationDigestService)->run();

        Mail::assertQueued(NotificationDigestMail::class, function (NotificationDigestMail $mail): bool {
            $recipient = $mail->to[0]['address'] ?? null;

            return $mail->totalUnread === 25
                && $recipient === 'sub@ogami.test'
                && $mail->envelope()->subject === '[OGAMI] Notification summary: 25 unread'
                && ! property_exists($mail, 'items')
                && str_contains($mail->render(), '25 unread notifications')
                && ! str_contains($mail->render(), 'Private item');
        });

        $this->assertSame(25, $result['notifications_summarised']);
    }

    public function test_digest_renders_html_and_plain_text_alternatives(): void
    {
        config()->set('app.frontend_url', 'https://app.ogamiph.test');
        Mail::mailer('array')->to('digest@ogami.test')->send(
            (new NotificationDigestMail('Juan', 3))->from('noreply@ogami.test', 'OGAMI'),
        );

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('3 unread notifications', $html);
        $this->assertLessThan(102_400, strlen($html));
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('null', strtolower($html));
        $this->assertNotEmpty($text);
        $this->assertStringContainsString('**3** unread notifications', $text);
        $this->assertStringContainsString('https://app.ogamiph.test/notifications', $text);
    }

    public function test_large_backlog_uses_only_a_count_query_and_exact_total(): void
    {
        $user = User::factory()->create(['email' => 'sub@ogami.test']);
        $this->optIn($user);

        for ($i = 0; $i < 100; $i++) {
            $this->notify($user, title: "Item {$i}");
        }

        Mail::fake();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = (new NotificationDigestService)->run();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $notificationQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'notifications'),
        ));

        $this->assertCount(1, $notificationQueries, 'Digest must never load notification bodies.');
        $this->assertStringContainsString('count(*)', strtolower($notificationQueries[0]['query']));

        Mail::assertQueued(NotificationDigestMail::class, function (NotificationDigestMail $mail): bool {
            return $mail->totalUnread === 100;
        });
        $this->assertSame(100, $result['notifications_summarised']);
    }

    public function test_subscriber_without_an_email_address_is_skipped(): void
    {
        $user = User::factory()->create(['email' => '']);
        $this->optIn($user);
        $this->notify($user);

        Mail::fake();

        $result = (new NotificationDigestService)->run();

        Mail::assertNothingQueued();
        $this->assertSame(0, $result['emails_sent']);
    }

    public function test_inactive_subscriber_is_not_emailed(): void
    {
        $user = User::factory()->create(['email' => 'inactive@ogami.test', 'is_active' => false]);
        $this->optIn($user);
        $this->notify($user);

        Mail::fake();

        (new NotificationDigestService)->run();

        Mail::assertNothingQueued();
    }

    public function test_invalid_email_address_is_not_emailed(): void
    {
        $user = User::factory()->create(['email' => 'not-an-email']);
        $this->optIn($user);
        $this->notify($user);

        Mail::fake();

        (new NotificationDigestService)->run();

        Mail::assertNothingQueued();
    }

    public function test_per_type_digest_row_is_not_treated_as_a_global_opt_in(): void
    {
        // Only '*' / 'all' / 'digest' are global opt-ins; a per-type row is not.
        $user = User::factory()->create(['email' => 'sub@ogami.test']);
        $this->optIn($user, 'leave.approved');
        $this->notify($user);

        Mail::fake();

        (new NotificationDigestService)->run();

        Mail::assertNothingQueued();
    }

    public function test_disabled_opt_in_row_does_not_receive_a_digest(): void
    {
        $user = User::factory()->create(['email' => 'sub@ogami.test']);
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id,
            'notification_type' => '*',
            'channel' => 'digest',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->notify($user);

        Mail::fake();

        (new NotificationDigestService)->run();

        Mail::assertNothingQueued();
    }

    public function test_mail_enqueue_failure_is_reported_for_scheduler_recovery(): void
    {
        $user = User::factory()->create(['email' => 'sub@ogami.test']);
        $this->optIn($user);
        $this->notify($user);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));

        $result = (new NotificationDigestService)->run();

        $this->assertSame(1, $result['failures']);
        $this->assertSame(0, $result['emails_sent']);
        $this->assertSame(1, $result['users_evaluated']);
    }
}
