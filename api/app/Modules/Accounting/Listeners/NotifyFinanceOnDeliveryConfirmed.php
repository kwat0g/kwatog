<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyFinanceOnDeliveryConfirmed implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications, private readonly ?SettingsService $settings = null) {}

    public function handle(DeliveryConfirmed $event): void
    {
        try {
            $invoice = $event->invoiceId ? Invoice::find($event->invoiceId) : null;

            $permission = $invoice ? 'accounting.invoices.view' : 'accounting.invoices.create';

            $roles = array_values(array_filter((array) ($this->settings ?? app(SettingsService::class))->get('accounting.delivery_confirmed.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $financeUsers = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->hasPermission($permission));

            $data = $invoice
                ? [
                    'title' => 'Draft invoice ready',
                    'message' => "Draft invoice {$invoice->invoice_number} is ready for review.",
                    'link_to' => "/accounting/invoices/{$invoice->hash_id}",
                    'entity_type' => 'invoice',
                    'entity_id' => $invoice->hash_id,
                ]
                : [
                    'title' => 'Delivery confirmed; manual invoice needed',
                    'message' => 'Automatic invoicing did not create a draft. Open invoice creation to continue manually.',
                    'link_to' => '/accounting/invoices/create',
                ];

            $this->notifications->send($financeUsers, 'chain.delivery_confirmed', $data);
        } catch (\Throwable $e) {
            Log::warning('NotifyFinanceOnDeliveryConfirmed failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
