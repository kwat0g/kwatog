<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Common\Services\EmailBrandingService;
use App\Modules\Production\Notifications\DailyProductionSummary;
use App\Modules\Production\Notifications\WeeklyProductionSummary;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProductionSummaryEmailDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_summary_is_branded_escaped_and_keeps_large_reports_compact(): void
    {
        $this->configureMail();
        $workOrders = array_map(static fn (int $i): array => [
            'wo_number' => 'WO-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'product_name' => $i === 1 ? '<img src=x onerror=alert(1)>' : 'Product '.$i,
            'quantity_target' => 100,
            'good' => 90,
            'reject' => 2,
            'status' => 'in_progress',
        ], range(1, 11));
        $breakdowns = array_map(static fn (int $i): array => [
            'machine_code' => 'M-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'machine_name' => 'Machine '.$i,
            'end_time' => $i === 1 ? 'ongoing' : '2026-10-21 09:00:00',
            'duration_min' => 12,
        ], range(1, 6));

        Notification::route('mail', 'manager@example.test')->notify(new DailyProductionSummary([
            'date' => '2026-10-21',
            'totals' => ['good' => 990, 'reject' => 22, 'total_units' => 1012, 'scrap_rate' => 2.17],
            'qc' => ['passed' => 5, 'failed' => 1, 'total' => 6],
            'wos' => $workOrders,
            'breakdowns' => $breakdowns,
            'defects' => [['code' => 'SHORT', 'name' => 'Short shot', 'count' => 4]],
        ]));

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();

        $this->assertStringStartsWith('['.app(EmailBrandingService::class)->data()['name'].'] Daily production summary:', $message->getSubject());
        $this->assertStringContainsString('21 Oct 2026', $html);
        $this->assertStringContainsString('In Progress', $html);
        $this->assertStringNotContainsString('in_progress', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('1 additional work order available', $html);
        $this->assertStringContainsString('1 additional breakdown available', $html);
        $this->assertStringNotContainsString('WO-011', $html);
        $this->assertStringContainsString('https://app.ogamiph.test/production/work-orders', $text);
        $this->assertLessThan(102_400, strlen($html));
    }

    public function test_weekly_summary_explains_unavailable_comparison_and_has_plain_text(): void
    {
        $this->configureMail();

        Notification::route('mail', 'manager@example.test')->notify(new WeeklyProductionSummary([
            'range_start' => '2026-10-12',
            'range_end' => '2026-10-18',
            'good' => 1500,
            'reject' => 24,
            'wow_delta_pct' => null,
        ]));

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();

        $this->assertStringStartsWith('['.app(EmailBrandingService::class)->data()['name'].'] Weekly production summary:', $message->getSubject());
        $this->assertStringContainsString('12 Oct 2026 – 18 Oct 2026', $html);
        $this->assertStringContainsString('Not comparable; prior week had no good units', $html);
        $this->assertStringContainsString('Open production work orders', $text);
        $this->assertStringContainsString('https://app.ogamiph.test/production/work-orders', $text);
    }

    private function configureMail(): void
    {
        $this->seed(SettingsSeeder::class);
        config([
            'app.env' => 'testing',
            'app.frontend_url' => 'https://app.ogamiph.test',
            'mail.default' => 'array',
            'mail.from.address' => 'reports@example.test',
            'mail.from.name' => 'OGAMI test',
        ]);
    }
}
