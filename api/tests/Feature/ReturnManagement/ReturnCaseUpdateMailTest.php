<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\ReturnManagement\Mail\ReturnCaseUpdateMail;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

final class ReturnCaseUpdateMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_supplier_notices_use_branded_safe_portal_links_and_plain_text(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test']);

        foreach (['customer', 'supplier'] as $realm) {
            $mail = new ReturnCaseUpdateMail('CASE-2026-0032', 'Under review', $realm, 'casehash123', []);
            $content = $mail->content();
            $html = $mail->render();
            $text = (string) app(Markdown::class)->renderText($content->markdown, $content->with);
            $previewDirectory = getenv('EMAIL_PREVIEW_DIR');
            if (is_string($previewDirectory) && trim($previewDirectory) !== '') {
                if (! is_dir($previewDirectory)) {
                    mkdir($previewDirectory, 0777, true);
                }
                file_put_contents($previewDirectory.'/return-case-'.$realm.'.html', $html);
            }
            $portalUrl = "https://erp.example.test/portal/{$realm}/problems/casehash123";

            $this->assertSame('[Ogami Philippines] Return case update: CASE-2026-0032', $mail->envelope()->subject);
            $this->assertStringContainsString($portalUrl, $html);
            $this->assertStringContainsString($portalUrl, $text);
            $this->assertStringContainsString('CASE-2026-0032', $html);
            $this->assertStringContainsString('Under review', $html);
            $this->assertStringContainsString('Philippine Ogami Corporation', $html);
            $this->assertStringContainsString('FCIE Complex', $html);
            $this->assertStringContainsString('automated notice', strtolower($html));
            $this->assertStringNotContainsString('null', strtolower($html));
            $this->assertStringNotContainsString('{{', $html);
            $this->assertLessThan(102 * 1024, strlen($html));
            $this->assertSame([], $mail->attachments);
        }
    }

    public function test_untrusted_display_values_are_escaped_and_subject_header_is_sanitized(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://erp.example.test']);

        $mail = new ReturnCaseUpdateMail(
            "CASE-0032\r\nBcc: attacker@example.test",
            '<script>alert(1)</script><img src=x onerror=alert(2)>Under review',
            'customer',
            'casehash123',
            [],
        );
        $html = $mail->render();

        $this->assertSame(
            '[Ogami Philippines] Return case update: CASE-0032 Bcc: attacker@example.test',
            $mail->envelope()->subject,
        );
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $mail->envelope()->subject);
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringNotContainsString('onerror=', strtolower($html));
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('alert(2)', $html);
    }
}
