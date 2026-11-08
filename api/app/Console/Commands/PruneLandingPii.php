<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SettingsService;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Enums\NewsletterStatus;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Landing\Models\NewsletterSubscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneLandingPii extends Command
{
    protected $signature = 'landing:prune-pii {--months= : Override the configured retention period}';
    protected $description = 'Permanently delete expired landing-form personal data';

    public function handle(SettingsService $settings, \App\Modules\Landing\Services\ContactInquiryRealtimeService $realtime): int
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
        $inquiries = DB::transaction(function () use ($cutoff, $realtime): int {
            $deleted = ContactInquiry::withTrashed()
                ->where('created_at', '<', $cutoff)
                ->where(function ($query): void {
                    $query->whereNotNull('deleted_at')
                        ->orWhere('status', ContactInquiryStatus::Closed->value);
                })
                ->forceDelete();

            if ($deleted > 0) {
                $realtime->stage('deleted');
            }

            return $deleted;
        });

        $subscribers = NewsletterSubscriber::query()
            ->where('status', NewsletterStatus::Unsubscribed->value)
            ->whereNotNull('unsubscribed_at')
            ->where('unsubscribed_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$inquiries} inquiries and {$subscribers} newsletter subscribers older than {$months} month(s).");

        return self::SUCCESS;
    }
}
