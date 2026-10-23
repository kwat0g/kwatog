<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Common\Mail\EmailIntegrationTestMail;
use App\Common\Services\EmailBrandingService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarkdownTextBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_markdown_text_alternative_uses_company_branding(): void
    {
        $this->seed(SettingsSeeder::class);
        config([
            'app.env' => 'testing',
            'app.name' => 'Ogami ERP API test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
            'mail.from.address' => 'test@example.test',
            'mail.from.name' => 'Test sender',
        ]);

        Mail::mailer('array')->to('mailpit@example.test')->send(new EmailIntegrationTestMail);

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $brand = app(EmailBrandingService::class)->data();
        $text = (string) $message->getTextBody();

        $this->assertNotNull($message->getHtmlBody());
        $this->assertStringContainsString($brand['name'], $text);
        $this->assertStringContainsString($brand['legal_name'], $text);
        $this->assertStringContainsString($brand['address'], $text);
        $this->assertStringContainsString('Automated message.', $text);
        $this->assertStringNotContainsString('Ogami ERP API test', $text);
        $this->assertStringNotContainsString('https://api.example.test', $text);
        $this->assertStringNotContainsString('All rights reserved.', $text);
    }
}
