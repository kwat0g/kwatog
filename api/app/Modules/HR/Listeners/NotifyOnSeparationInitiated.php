<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SeparationInitiated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnSeparationInitiated implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(SeparationInitiated $event): void
    {
        try {
            $clearance = $event->clearance->loadMissing('employee');

            $roles = array_values(array_filter((array) $this->settings->get('hr.separation.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.separation_initiated', [
                'title'       => 'Separation Initiated',
                'message'     => "Separation initiated for {$clearance->employee?->full_name}.",
                'link_to'     => "/hr/employees/{$clearance->employee?->hash_id}",
                'entity_type' => 'clearance',
                'entity_id'   => $clearance->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnSeparationInitiated failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
