<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Common\Services\EmailBrandingService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Landing\Mail\ContactInquiryReceivedMail;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactInquiryReceivedMailDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_inquiry_email_is_minimal_branded_and_links_to_the_authenticated_spa(): void
    {
        $this->seed(SettingsSeeder::class);
        config([
            'app.env' => 'testing',
            'app.frontend_url' => 'https://app.ogamiph.test',
            'mail.default' => 'array',
            'mail.from.address' => 'no-reply@example.test',
            'mail.from.name' => 'Ogami Test',
        ]);

        Mail::mailer('array')->to('sales@example.test')->send(new ContactInquiryReceivedMail(
            'INQ-202610-0001',
            'safe-hash-id',
            '21 Oct 2026, 10:30 AM +08',
            'contact-inquiry|1',
        ));

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $brand = app(EmailBrandingService::class)->data();
        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();

        $this->assertStringStartsWith('[TESTING] ['.$brand['name'].'] New website inquiry: INQ-202610-0001', $message->getSubject());
        $this->assertSame('mailpit@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('no-reply@example.test', $message->getFrom()[0]->getAddress());
        $this->assertStringContainsString('INQ-202610-0001', $html);
        $this->assertStringContainsString('21 Oct 2026, 10:30 AM +08', $html);
        $this->assertStringContainsString($brand['legal_name'], $html);
        $this->assertStringContainsString('configured for website contact inquiries', $text);
        $this->assertStringContainsString('Automated message.', $text);
        $this->assertStringContainsString('https://app.ogamiph.test/crm/inquiries/safe-hash-id', $text);
        $this->assertStringNotContainsString('api.', $text);
        $this->assertStringNotContainsString('sender@example.test', $html);
        $this->assertStringNotContainsString('sender@example.test', $text);
        $this->assertLessThan(102_400, strlen($html));
    }

    public function test_exhausted_delivery_uses_the_current_crm_permission_audience(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        (new ContactInquiryReceivedMail(
            'INQ-202610-0001',
            'safe-hash-id',
            '21 Oct 2026, 10:30 AM +08',
        ))->failed(new \RuntimeException('Transport failure'));

        $this->assertDatabaseHas('notifications', [
            'type' => 'email.delivery_failed',
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
        ]);
    }
}
