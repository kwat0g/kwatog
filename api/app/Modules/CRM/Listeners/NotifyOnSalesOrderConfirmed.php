<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnSalesOrderConfirmed implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(SalesOrderConfirmed $event): void
    {
        try {
            $so = $event->salesOrder;

            // The event carries the current row (ToleratesNewerModelState): an
            // order cancelled before this ran must not be announced as confirmed.
            if ($so->status === SalesOrderStatus::Cancelled) {
                return;
            }

            $roles = array_values(array_filter((array) $this->settings->get('crm.sales_order_confirmed.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::query()
                ->where('is_active', true)
                ->where(fn ($query) => ($event->actorUserId !== null ? $query->whereKey($so->created_by) : $query)
                    ->orWhereHas('role', fn ($role) => $role->whereIn('slug', $roles)))
                ->get()
                ->filter(fn (User $user): bool => $user->id !== $event->actorUserId
                    && $user->hasPermission('crm.sales_orders.view')
                    && (($event->actorUserId !== null && $user->id === (int) $so->created_by)
                        || ($user->hasPermission('production.schedule.view') && in_array($user->role?->slug, $roles, true))));

            $this->notifications->sendForSubject($so, $audience, 'chain.so_confirmed', [
                'title' => "Sales order {$so->so_number} confirmed",
                'message' => 'MRP planning has been queued. Review the order and schedule after it completes.',
                'link_to' => '/crm/sales-orders/'.$so->hash_id,
                'entity_type' => 'sales_order',
                'entity_id' => $so->hash_id,
            ], 'chain:so_confirmed:'.$so->id.':'.($so->confirmed_at ?? $so->updated_at)->toISOString());
        } catch (\Throwable $e) {
            Log::warning('NotifyOnSalesOrderConfirmed failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
