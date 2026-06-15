<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * T2.5 — Self-scoped delivery surface for drivers.
 *
 * All reads/writes are filtered to deliveries whose `driver_id` equals
 * the authenticated user. Status transitions and receipt-photo uploads
 * delegate to the existing DeliveryService. Confirmation (which auto-
 * creates an invoice) is intentionally NOT exposed here — drivers
 * cannot confirm; that's the CRM officer's job.
 */
class DriverDeliveryService
{
    /** Driver-allowed forward transitions. */
    private const ALLOWED = [
        'scheduled'  => 'loading',
        'loading'    => 'in_transit',
        'in_transit' => 'delivered',
    ];

    public function __construct(private readonly DeliveryService $deliveries) {}

    public function list(User $driver, array $filters): LengthAwarePaginator
    {
        $q = Delivery::query()
            ->where('driver_id', $driver->id)
            ->with([
                'salesOrder:id,so_number,customer_id',
                'salesOrder.customer:id,name',
                'vehicle:id,plate_number',
            ]);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        } else {
            // Default: hide finalised so the driver sees actionable rows.
            $q->whereNotIn('status', [DeliveryStatus::Confirmed->value, DeliveryStatus::Cancelled->value]);
        }

        return $q->orderBy('scheduled_date')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(User $driver, Delivery $delivery): Delivery
    {
        $this->assertOwned($driver, $delivery);
        return $this->deliveries->show($delivery);
    }

    public function updateStatus(User $driver, Delivery $delivery, string $next): Delivery
    {
        $this->assertOwned($driver, $delivery);

        $current = $delivery->status instanceof DeliveryStatus
            ? $delivery->status->value
            : (string) $delivery->status;

        $allowed = self::ALLOWED[$current] ?? null;
        if ($allowed !== $next) {
            throw ValidationException::withMessages([
                'status' => ["driver_cannot_transition_from_{$current}_to_{$next}"],
            ]);
        }

        return $this->deliveries->updateStatus($delivery, DeliveryStatus::from($next), null);
    }

    public function uploadReceipt(User $driver, Delivery $delivery, UploadedFile $file): Delivery
    {
        $this->assertOwned($driver, $delivery);
        return $this->deliveries->uploadReceiptPhoto($delivery, $file, $driver);
    }

    private function assertOwned(User $driver, Delivery $delivery): void
    {
        if ($delivery->driver_id !== $driver->id) {
            abort(404); // 404 hides existence — narrower than 403.
        }
    }
}
