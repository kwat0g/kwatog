<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\RequestForQuote;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Durable lifecycle signal for RFQ portal, email, and internal inbox delivery.
 * The kind is an allow-listed business value, not a class name from storage.
 */
final class RfqLifecycleEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $rfqId,
        public readonly string $rfqHashId,
        public readonly string $kind,
        public readonly ?int $purchaseOrderId = null,
        public readonly array $snapshot = [],
    ) {}

    public static function capture(RequestForQuote $rfq, string $kind): self
    {
        $rfq->loadMissing('invitations.vendor');

        return new self(
            (int) $rfq->id,
            (string) $rfq->hash_id,
            $kind,
            snapshot: [
                'rfq_number' => (string) $rfq->rfq_number,
                'title' => (string) $rfq->title,
                'closes_at' => $rfq->closes_at?->toIso8601String(),
                'last_extension_reason' => $rfq->last_extension_reason,
                'cancellation_reason' => $rfq->cancellation_reason,
                'suppliers' => $rfq->invitations->map(fn ($invitation): array => [
                    'invitation_id' => (int) $invitation->id,
                    'email' => $invitation->vendor?->email,
                    'contact_person' => $invitation->vendor?->contact_person,
                    'vendor_name' => $invitation->vendor?->name,
                    'status' => $invitation->status?->value ?? $invitation->status,
                ])->all(),
            ],
        );
    }
}
