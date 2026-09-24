<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SettingsService;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Enums\NewsletterStatus;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Landing\Models\NewsletterSubscriber;
use Illuminate\Console\Command;

class PruneLandingPii extends Command
{
    protected $signature = 'landing:prune-pii {--months= : Override the configured retention period}';
    protected $description = 'Permanently delete expired landing-form personal data';

    public function handle(SettingsService $settings): int
    {
        $configured = $settings->requiredInt('landing.pii_retention_months', 1);
        $months = $this->option('months') === null
            ? $configured
            : (int) $this->option('months');

        if ($months < 1) {
            $this->error('The retention period must be at least one month.');

            return self::FAILURE;
        }

        $cutoff = now()->subMonths($months);
        $inquiries = ContactInquiry::withTrashed()
            ->where('created_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('deleted_at')
                    ->orWhere('status', ContactInquiryStatus::Closed->value);
            })
            ->forceDelete();

        $subscribers = NewsletterSubscriber::query()
            ->where('status', NewsletterStatus::Unsubscribed->value)
            ->whereNotNull('unsubscribed_at')
            ->where('unsubscribed_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$inquiries} inquiries and {$subscribers} newsletter subscribers older than {$months} month(s).");

        return self::SUCCESS;
    }
}
