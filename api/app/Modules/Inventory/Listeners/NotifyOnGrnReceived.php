<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnGrnReceived implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications, private readonly ?SettingsService $settings = null) {}

    public function handle(GoodsReceiptNoteCreated $event): void
    {
        try {
            $grn = $event->grn->loadMissing('purchaseOrder:id,po_number');

            $roles = array_values(array_filter((array) ($this->settings ?? app(SettingsService::class))->get('inventory.grn_received.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            $ref = $grn->purchaseOrder?->po_number ?? $grn->grn_number;

            $this->notifications->send($audience, 'inventory.grn_received', [
                'title'       => "GRN Received — {$grn->grn_number}",
                'message'     => "Goods received against {$ref}. Incoming QC in progress.",
                'link_to'     => "/inventory/grn/{$grn->hash_id}",
                'entity_type' => 'grn',
                'entity_id'   => $grn->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnGrnReceived failed', ['error' => $e->getMessage()]);
        }
    }
}
