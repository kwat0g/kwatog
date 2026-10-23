<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Common\Mail\UserNotificationMail;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

final class UserNotificationMailDesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test']);
    }

    public function test_notification_uses_shared_branding_spa_link_and_plain_text(): void
    {
        $mail = new UserNotificationMail('approval.action_required', [
            'title' => 'Approval needed: PO-2026-0042',
            'message' => 'Purchase order PO-2026-0042 needs your review.',
            'link_to' => '/purchasing/purchase-orders/pohash123',
        ], 'Aiko Tanaka', 42);
        $html = $mail->render();
        $text = (string) app(Markdown::class)->renderText($mail->content()->markdown, $mail->content()->with);
        $url = 'https://erp.example.test/purchasing/purchase-orders/pohash123';
        $previewDirectory = getenv('EMAIL_PREVIEW_DIR');
        if (is_string($previewDirectory) && trim($previewDirectory) !== '') {
            if (! is_dir($previewDirectory)) {
                mkdir($previewDirectory, 0777, true);
            }
            file_put_contents($previewDirectory.'/user-notification.html', $html);
        }

        $this->assertSame('[Ogami Philippines] Approval needed: PO-2026-0042', $mail->envelope()->subject);
        $this->assertStringContainsString('<title>Approval needed: PO-2026-0042</title>', $html);
        $this->assertStringContainsString('Aiko Tanaka', $html);
        $this->assertStringContainsString('Purchase order PO-2026-0042 needs your review.', $html);
        $this->assertStringContainsString($url, $html);
        $this->assertStringContainsString($url, $text);
        $this->assertStringContainsString('Philippine Ogami Corporation', $html);
        $this->assertStringContainsString('FCIE Complex', $html);
        $this->assertStringContainsString('cid:ogami-logo@ogami', $html);
        $this->assertStringContainsString('Open this link if the button does not work', $html);
        $this->assertDoesNotMatchRegularExpression('/\{\{|\b(?:undefined|null)\b/i', $html);
        $this->assertLessThan(102 * 1024, strlen($html));
    }

    public function test_notification_escapes_hostile_content_and_rejects_external_links(): void
    {
        $mail = new UserNotificationMail('approval.action_required', [
            'title' => "Approval\r\nReview this request",
            'message' => 'Internal <script>alert(1)</script> note <img src=x onerror=alert(2)> [phish](javascript:alert(3))',
            'link_to' => 'https://evil.example/phish',
        ], 'A. <img src=x onerror=alert(4)> User');
        $html = $mail->render();
        $text = (string) app(Markdown::class)->renderText($mail->content()->markdown, $mail->content()->with);

        $this->assertStringNotContainsString("\r", $mail->envelope()->subject);
        $this->assertStringNotContainsString("\n", $mail->envelope()->subject);
        $this->assertStringNotContainsString("\r", $html);
        $this->assertStringNotContainsString("\n", $mail->content()->with['pageTitle']);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=', $html);
        $this->assertDoesNotMatchRegularExpression('/<a\b[^>]*href=["\']javascript:/i', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('Internal', $text);
        $this->assertStringNotContainsString('evil.example', $text);
    }

    public function test_notification_rebuilds_same_origin_absolute_link_from_frontend_base(): void
    {
        $mail = new UserNotificationMail('sales_order.delivery_proposed', [
            'title' => 'Delivery date proposal for SO-2026-0042',
            'message' => 'Review and respond in your customer portal.',
            'link_to' => 'https://erp.example.test/portal/customer/orders/sohash123',
        ]);

        $this->assertStringContainsString(
            'href="https://erp.example.test/portal/customer/orders/sohash123"',
            $mail->render(),
        );
    }

    public function test_non_string_fields_use_safe_fallbacks(): void
    {
        $mail = new UserNotificationMail('test.malformed', [
            'title' => ['unexpected' => 'array'],
            'message' => ['unexpected' => 'array'],
            'link_to' => 123,
        ]);
        $html = $mail->render();

        $this->assertSame('[Ogami Philippines] Notification', $mail->envelope()->subject);
        $this->assertStringContainsString('Open OGAMI to review this notification.', $html);
        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringNotContainsString('>null<', $html);
        $this->assertStringNotContainsString('href="123"', $html);
    }
}
