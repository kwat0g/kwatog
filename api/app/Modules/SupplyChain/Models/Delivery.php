<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Enums\DeliveryInvoiceHandoffStatus;
use App\Modules\SupplyChain\Enums\DeliveryCocHandoffStatus;
use App\Modules\SupplyChain\Enums\DeliveryCostingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'automatic_supply_key',
        'delivery_number', 'sales_order_id', 'vehicle_id', 'driver_id',
        'status', 'scheduled_date', 'original_scheduled_date', 'reschedule_count',
        'expected_delivery_date', 'carrier',
        'departed_at', 'delivered_at',
        'confirmed_at', 'confirmed_by', 'receipt_photo_path',
        'invoice_id', 'notes', 'created_by',
        'idempotency_key', 'idempotency_fingerprint',
        'invoice_handoff_status', 'invoice_handoff_message', 'invoice_handoff_at',
        'coc_handoff_status', 'coc_handoff_message', 'coc_handoff_at',
        // ADV7 — Proof of Delivery receiver capture.
        'receiver_name', 'receiver_position', 'received_at', 'delivery_remarks',
        'cost_recognition_mode',
        // Chain 1 — ship-to snapshot taken while the run sheet was prepared.
        'delivery_address', 'delivery_contact_person', 'delivery_contact_phone',
    ];

    protected $casts = [
        'status'         => DeliveryStatus::class,
        'scheduled_date' => 'date',
        'expected_delivery_date' => 'date',
        'original_scheduled_date' => 'date',
        'reschedule_count' => 'integer',
        'departed_at'    => 'datetime',
        'delivered_at'   => 'datetime',
        'confirmed_at'   => 'datetime',
        'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::class,
        'invoice_handoff_at' => 'datetime',
        'coc_handoff_status' => DeliveryCocHandoffStatus::class,
        'coc_handoff_at' => 'datetime',
        'received_at'    => 'datetime',
        'cost_recognition_mode' => DeliveryCostingMode::class,
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** ADV3 — most recent shipment lot for this delivery, if any. */
    public function shipmentLot(): HasOne
    {
        return $this->hasOne(ShipmentLot::class)->latestOfMany('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    /**
     * The same open report holds both receipt confirmation and billing.
     *
     * A `delivery_trace` intake ("the shipment has not arrived") carries no
     * lines by design, so matching only on line quantities let a delivery be
     * confirmed and invoiced while the customer was still waiting for it.
     * Rejected reports are closed and must not hold money.
     */
    public function blockingReturnCase(): HasOne
    {
        return $this->hasOne(\App\Modules\ReturnManagement\Models\ReturnCase::class)
            ->whereNotIn('status', ['resolved', 'withdrawn', 'rejected'])
            ->where(fn ($query) => $query
                ->where('intake_kind', \App\Modules\ReturnManagement\Enums\ReturnCaseIntakeKind::DeliveryTrace->value)
                ->orWhereHas('lines', fn ($q) => $q->where('missing_quantity', '>', 0)->orWhere('defective_quantity', '>', 0)))
            ->orderBy('id');
    }

    public function quantityDiscrepancy(): HasOne
    {
        return $this->hasOne(DeliveryQuantityDiscrepancy::class);
    }

    public function attemptOutcome(): HasOne
    {
        return $this->hasOne(DeliveryAttemptOutcome::class);
    }

    public function stockReservationBatch(): HasOne
    {
        return $this->hasOne(DeliveryStockReservationBatch::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(DeliveryStockReservation::class);
    }

    public function costHandoffs(): HasMany
    {
        return $this->hasMany(DeliveryCostHandoff::class)->orderBy('id');
    }

    /** ADV7 — proof-of-delivery files (signed DRs, photos, customer PO confirmations). */
    public function proofs(): HasMany
    {
        return $this->hasMany(DeliveryProof::class);
    }

    /** Append-only history of date moves for this delivery. */
    public function reschedules(): HasMany
    {
        return $this->hasMany(DeliveryReschedule::class);
    }

    /**
     * Where this run sheet tells the driver to go — the ONE resolution.
     *
     * Snapshot first (what dispatch actually issued), then the customer's
     * ship-to, then the customer's registered/billing address so a delivery
     * raised before the ship-to columns existed still prints something a driver
     * can drive to instead of an empty row. Callers must NOT re-express this
     * order; the office page, the driver PWA and the printed receipt all read
     * these three methods.
     */
    public function resolvedDeliveryAddress(): ?string
    {
        $snapshot = trim((string) ($this->delivery_address ?? ''));
        if ($snapshot !== '') {
            return $snapshot;
        }

        $customer = $this->salesOrder?->customer;
        $shipTo = trim((string) ($customer?->delivery_address ?? ''));
        if ($shipTo !== '') {
            return $shipTo;
        }

        $billing = trim((string) ($customer?->address ?? ''));

        return $billing !== '' ? $billing : null;
    }

    public function resolvedDeliveryContactPerson(): ?string
    {
        return $this->firstFilledValue(
            $this->delivery_contact_person,
            $this->salesOrder?->customer?->delivery_contact_person,
            $this->salesOrder?->customer?->contact_person,
        );
    }

    public function resolvedDeliveryContactPhone(): ?string
    {
        return $this->firstFilledValue(
            $this->delivery_contact_phone,
            $this->salesOrder?->customer?->delivery_contact_phone,
            $this->salesOrder?->customer?->phone,
        );
    }

    private function firstFilledValue(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function scopeStatus(Builder $q, DeliveryStatus|string $s): Builder
    {
        return $q->where('status', $s instanceof DeliveryStatus ? $s->value : $s);
    }
}
