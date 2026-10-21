<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Services\EmailBrandingService;
use App\Common\Notifications\CriticalAlertEmail;
use Database\Seeders\SettingsSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CriticalAlertEmailDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_alert_uses_shared_branding_and_omits_internal_entity_id(): void
    {
        $this->seed(SettingsSeeder::class);
        config([
            'app.env' => 'testing',
            'app.frontend_url' => 'https://app.ogamiph.test',
            'mail.default' => 'array',
            'mail.from.address' => 'alerts@example.test',
            'mail.from.name' => 'OGAMI test',
        ]);
        $alert = Alert::query()->create([
            'type' => AlertType::SchedulerStale,
            'severity' => AlertSeverity::Critical,
            'title' => 'Scheduler degraded',
            'message' => 'The job runner has not recorded a heartbeat.',
            'entity_type' => 'App\\Common\\Models\\SchedulerTaskRun',
            'entity_id' => 987654,
        ]);
        $alert->created_at = CarbonImmutable::parse('2026-10-21 08:15:00', 'Asia/Manila');
        $alert->save();

        Notification::route('mail', 'alerts@example.test')->notify(new CriticalAlertEmail($alert));

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();

        $this->assertStringContainsString('['.app(EmailBrandingService::class)->data()['name'].'] Critical alert: Scheduler degraded', $message->getSubject());
        $this->assertStringContainsString('Scheduler degraded', $html);
        $this->assertStringContainsString('Scheduler stale', $html);
        $this->assertStringContainsString('21 Oct 2026, 8:15 AM +08:00', $html);
        $this->assertStringNotContainsString('987654', $html);
        $this->assertStringNotContainsString('scheduler_stale', $html);
        $this->assertStringContainsString('https://app.ogamiph.test/alerts', $text);
        $this->assertStringContainsString('Review alerts', $text);
        $this->assertLessThan(102_400, strlen($html));
    }
}
