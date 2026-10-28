<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Contracts\SupplierDispatchGateway;
use App\Modules\Purchasing\Mail\SupplierPurchaseOrderMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use App\Modules\Purchasing\Support\SupplierDispatchResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Supplier portal + email dispatch adapter.
 *
 * Publication notifies active supplier portal users. The dispatch service then
 * marks the PO sent, making it visible in their portal. A usable vendor email
 * also receives a queued copy; email alone does not prove delivery.
 */
class SupplierPortalDispatchGateway implements SupplierDispatchGateway
{
    public function publish(PurchaseOrder $purchaseOrder, string $idempotencyKey): SupplierDispatchResult
    {
        $purchaseOrder->loadMissing(['vendor', 'items.item']);

        $recipientCount = SupplierPortalUser::query()
            ->where('vendor_id', $purchaseOrder->vendor_id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->count();

        app(NotificationService::class)->sendPortalInApp(SupplierPortalUser::where('vendor_id', $purchaseOrder->vendor_id)->where('is_active', true)->get(), 'purchase_order.published', [
            'title' => 'Review purchase order '.$purchaseOrder->po_number, 'message' => 'A purchase order is available. Review it and accept, propose changes, or decline before scheduling delivery.',
            'link_to' => '/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id, 'entity_type' => 'purchase_order', 'entity_id' => $purchaseOrder->hash_id,
        ], 'po:'.$purchaseOrder->id.':published:'.$idempotencyKey);
        $emailQueued = false;
        $emailFailure = null;
        $vendorEmail = $purchaseOrder->vendor?->email;
        $fallbackUserIds = $this->fallbackUserIds($purchaseOrder);

        if (filter_var($vendorEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($vendorEmail)->queue(new SupplierPurchaseOrderMail(
                    $purchaseOrder,
                    $fallbackUserIds,
                ));
                $emailQueued = true;
            } catch (\Throwable $e) {
                $emailFailure = 'Automatic supplier email could not be queued; use the portal or an approved alternate channel.';
                $this->notifyEmailFailure($purchaseOrder, $emailFailure, $fallbackUserIds);
                Log::warning('Supplier purchase order email enqueue failed', [
                    'purchase_order_id' => $purchaseOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $emailFailure = 'The supplier has no usable email address; use the portal or an approved alternate channel.';
            $this->notifyEmailFailure($purchaseOrder, $emailFailure, $fallbackUserIds);
        }

        $metadata = [
            'idempotency_key' => $idempotencyKey,
            'supplier_email_queued' => $emailQueued,
            'supplier_email_address_present' => is_string($vendorEmail) && trim($vendorEmail) !== '',
        ];
        if ($emailFailure !== null) {
            $metadata['supplier_email_fallback_required'] = true;
        }

        if ($recipientCount < 1) {
            return SupplierDispatchResult::manualRequired(
                'No active supplier portal user is available. '.($emailFailure ?? 'Send the approved PO PDF through an approved channel, then confirm transmission.'),
                $metadata + ['next_action' => 'send_pdf_and_confirm'],
            );
        }

        return SupplierDispatchResult::portalAvailable($recipientCount, [
            ...$metadata,
            'delivery_mode' => $emailQueued ? 'portal_and_email' : 'pull_based_portal',
        ]);
    }

    /** @return list<int> */
    private function fallbackUserIds(PurchaseOrder $purchaseOrder): array
    {
        return User::query()
            ->where('is_active', true)
            ->with('role')
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission('purchasing.view')
                && app(PurchaseOrderAccessPolicy::class)->canView($user, $purchaseOrder))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @param list<int> $fallbackUserIds */
    private function notifyEmailFailure(PurchaseOrder $purchaseOrder, string $message, array $fallbackUserIds): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $fallbackUserIds,
            'Supplier purchase order',
            "PO {$purchaseOrder->po_number}: {$message}",
            [
                'link_to' => '/purchasing/purchase-orders/'.$purchaseOrder->hash_id,
                'entity_type' => 'purchase_order',
                'entity_id' => $purchaseOrder->hash_id,
                'reason' => 'The supplier email was missing, invalid, unreachable, or rejected by the email provider.',
            ],
            $purchaseOrder,
        );
    }
}
