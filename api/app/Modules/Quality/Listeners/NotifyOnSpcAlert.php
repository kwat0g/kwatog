<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Events\SpcAlertTriggered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnSpcAlert implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SpcAlertTriggered $event): void
    {
        try {
            $chart = $event->chart->load('product', 'specItem');
            $recipients = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['qc_inspector', 'production_manager']))
                ->where('is_active', true)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $ruleNames = array_map(fn ($r) => $r->value, $event->violations);

            $this->notifications->send($recipients, 'spc_alert', [
                'title'   => 'SPC Alert: ' . ($chart->product->name ?? 'Unknown') . ' — ' . ($chart->specItem->parameter_name ?? ''),
                'message' => 'Control chart violation detected: ' . implode(', ', $ruleNames),
                'url'     => '/quality/spc/charts/' . $chart->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SPC alert notification failed: ' . $e->getMessage());
        }
    }
}
