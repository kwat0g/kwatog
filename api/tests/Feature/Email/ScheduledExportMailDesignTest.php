<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Common\Enums\ExportFormat;
use App\Common\Mail\ScheduledExportMail;
use App\Common\Services\EmailBrandingService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScheduledExportMailDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_export_mail_uses_brand_route_and_html_text_alternatives_with_exact_attachment(): void
    {
        $this->seed(SettingsSeeder::class);
        config(['app.frontend_url' => 'https://app.ogamiph.test']);
        config(['mail.from.address' => 'sender@example.test', 'mail.from.name' => 'OGAMI Test']);

        Mail::mailer('array')->to('report@example.test')->send(
            (new ScheduledExportMail(
                "Quarterly\n[Operations]",
                'hr.employees',
                'employees-20261021.csv',
                'selected-export-bytes',
                ExportFormat::Csv,
                generatedAt: '21 Oct 2026, 8:00 AM +08',
            ))->from('sender@example.test', 'OGAMI Test'),
        );

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();
        $attachment = $message->getAttachments()[0];

        $this->assertStringContainsString('['.app(EmailBrandingService::class)->data()['name'].'] Scheduled export ready: Quarterly [Operations]', $message->getSubject());
        $this->assertStringContainsString('Employees', $html);
        $this->assertStringNotContainsString('hr.employees', $html);
        $this->assertStringContainsString('21 Oct 2026, 8:00 AM +08', $html);
        $this->assertStringContainsString('https://app.ogamiph.test/admin/scheduled-exports', $text);
        $this->assertStringContainsString('The file for your saved export schedule is attached.', $text);
        $this->assertSame('employees-20261021.csv', $attachment->getFilename());
        $this->assertSame('selected-export-bytes', $attachment->getBody());
        $this->assertLessThan(102_400, strlen($html));
    }
}
