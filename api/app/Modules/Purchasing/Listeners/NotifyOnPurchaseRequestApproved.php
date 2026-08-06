<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnPurchaseRequestApproved implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications, private readonly ?SettingsService $settings = null) {}

    public function handle(PurchaseRequestApproved $event): void
    {
        try {
            $pr = $event->purchaseRequest;

            $roles = array_values(array_filter((array) ($this->settings ?? app(SettingsService::class))->get('purchasing.purchase_request_approved.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.pr_approved', [
                'title'       => "PR {$pr->pr_number} Approved",
                'message'     => "Ready to convert to PO.",
                'link_to'     => "/purchasing/purchase-requests/{$pr->hash_id}",
                'entity_type' => 'purchase_request',
                'entity_id'   => $pr->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnPurchaseRequestApproved failed', ['error' => $e->getMessage()]);
        }
    }
}
