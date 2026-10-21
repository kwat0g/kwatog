<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PasswordResetRequest;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\PasswordResetNotification;
use App\Modules\Auth\Notifications\WelcomeNotification;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Tests\TestCase;

class AccountAccessNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://app.ogamiph.test']);
    }

    public function test_legacy_welcome_queue_payload_gets_a_fresh_setup_link_without_its_password(): void
    {
        $user = User::factory()->create(['email' => 'new.user@ogami.test']);
        $notification = new WelcomeNotification('https://app.ogamiph.test/unused', 60);
        $this->simulateLegacyQueuePayload($notification, 'Legacy-Temp-Secret!');

        $html = (string) $notification->toMail($user)->render();

        $this->assertFreshLinkReplacesPassword($html, $user);
    }

    public function test_legacy_password_reset_queue_payload_gets_a_fresh_link_without_its_password(): void
    {
        $user = User::factory()->create(['email' => 'reset.user@ogami.test']);
        $notification = new PasswordResetNotification('https://app.ogamiph.test/unused', 60);
        $this->simulateLegacyQueuePayload($notification, 'Legacy-Reset-Secret!');

        $html = (string) $notification->toMail($user)->render();

        $this->assertFreshLinkReplacesPassword($html, $user);
    }

    public function test_account_access_mail_has_html_and_plain_text_parts_and_uses_the_nonproduction_sink(): void
    {
        config([
            'app.env' => 'testing',
            'mail.default' => 'array',
            'mail.safety_recipient' => 'mailpit@example.test',
            'mail.from.address' => 'erp@example.test',
            'mail.from.name' => 'OGAMI ERP Test',
        ]);
        $user = User::factory()->create(['email' => 'new.user@ogami.test']);

        app(MailChannel::class)->send(
            $user,
            new WelcomeNotification('https://app.ogamiph.test/reset-password?token=sample-setup-token', 60),
        );

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame(['mailpit@example.test'], array_map(
            static fn ($address) => $address->getAddress(),
            $message->getTo(),
        ));
        $this->assertSame(['erp@example.test'], array_map(
            static fn ($address) => $address->getAddress(),
            $message->getFrom(),
        ));
        $this->assertSame(['mailpit@example.test'], array_map(
            static fn ($address) => $address->getAddress(),
            $message->getReplyTo(),
        ));
        $this->assertStringStartsWith('[TESTING] ', (string) $message->getSubject());
        $this->assertStringContainsString('Your account is ready', (string) $message->getHtmlBody());
        $this->assertStringContainsString('sample-setup-token', (string) $message->getTextBody());
        $this->assertStringNotContainsString('Legacy-Temp-Secret!', (string) $message->getHtmlBody());
    }

    private function simulateLegacyQueuePayload(WelcomeNotification|PasswordResetNotification $notification, string $legacyPassword): void
    {
        (new ReflectionProperty($notification, 'resetUrl'))->setValue($notification, null);
        (new ReflectionProperty($notification, 'tempPassword'))->setValue($notification, $legacyPassword);
    }

    private function assertFreshLinkReplacesPassword(string $html, User $user): void
    {
        $this->assertStringContainsString('https://app.ogamiph.test/reset-password?token=', $html);
        $this->assertStringNotContainsString('Legacy-', $html);

        preg_match('~reset-password\?token=([A-Za-z0-9]+)~', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertSame(hash('sha256', $matches[1]), PasswordResetRequest::where('user_id', $user->id)->sole()->token_hash);
    }
}
