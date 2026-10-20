<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Modules\CRM\Models\CustomerComplaint;
use Illuminate\Support\Str;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerComplaintUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CustomerComplaint $complaint,
        public readonly string $action = 'updated',
        public ?array $snapshot = null,
    ) {
        $this->snapshot ??= $this->captureSnapshot($complaint);
    }

    /** @return array<string, mixed> */
    public function snapshotValues(): array
    {
        return $this->snapshot ??= $this->captureSnapshot($this->complaint);
    }

    /** @return array<string, mixed> */
    private function captureSnapshot(CustomerComplaint $complaint): array
    {
        $complaint->loadMissing(['customer', 'product']);
        $status = $complaint->status;
        $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $product = $complaint->product;

        return [
            'complaint_hash_id' => (string) $complaint->hash_id,
            'complaint_number' => (string) $complaint->complaint_number,
            'status_label' => Str::headline($status),
            'received_date' => $complaint->received_date?->format('M d, Y'),
            'product_label' => $product ? trim($product->part_number.' — '.$product->name) : null,
            'recipient_name' => (string) ($complaint->customer?->contact_person ?: $complaint->customer?->name ?: 'Customer'),
            'recipient_email' => $complaint->customer?->email,
            'portal_url' => $base.'/portal/customer/complaints',
        ];
    }
}
