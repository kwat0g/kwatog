<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Enums\SupplierDispatchStatus;

final readonly class SupplierDispatchResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public SupplierDispatchStatus $status,
        public string $channel,
        public int $recipientCount = 0,
        public ?string $note = null,
        public array $metadata = [],
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function portalAvailable(int $recipientCount, array $metadata = []): self
    {
        return new self(
            SupplierDispatchStatus::PortalAvailable,
            'supplier_portal',
            $recipientCount,
            'The approved PO is available to active supplier portal users. Confirm transmission after the supplier is actually notified.',
            $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public static function manualRequired(string $note, array $metadata = []): self
    {
        return new self(
            SupplierDispatchStatus::ManualRequired,
            'manual',
            0,
            $note,
            $metadata,
        );
    }
}
