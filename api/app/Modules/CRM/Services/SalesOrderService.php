<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalesOrderService
{
    /** Philippines VAT rate. */
    private const VAT_RATE = 0.12;

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PriceAgreementService $prices,
    ) {}

    /**
     * Resolve the MRP engine via the container so this module's tests can run
     * without the MRP module being booted (and so unit tests can mock it).
     * The actual class lives in App\Modules\MRP\Services\MrpEngineService.
     */
    private function mrpEngine(): ?\App\Modules\MRP\Services\MrpEngineService
    {
        $cls = '\\App\\Modules\\MRP\\Services\\MrpEngineService';
        return class_exists($cls) ? app($cls) : null;
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $q = SalesOrder::query()
            ->with(['customer:id,name', 'creator:id,name'])
            ->withCount('items');

        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) $q->where('customer_id', $cid);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['date_from'])) {
            $q->whereDate('date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->whereDate('date', '<=', $filters['date_to']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('so_number', SearchOperator::like(), "%{$term}%")
                   ->orWhereHas('customer', fn ($c) => $c->where('name', SearchOperator::like(), "%{$term}%"));
            });
        }

        return $q->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(SalesOrder $so): SalesOrder
    {
        return $so->load([
            'customer',
            'creator:id,name',
            'items.product:id,part_number,name,unit_of_measure',
        ]);
    }

    /**
     * Create a draft SO. Resolves price-per-line via PriceAgreementService;
     * throws NoPriceAgreementException (422) if any line has no agreement.
     *
     * Wrapped in a transaction to keep so_number generation atomic.
     */
    public function create(array $data, int $userId): SalesOrder
    {
        return DB::transaction(function () use ($data, $userId) {
            $customerId = (int) $data['customer_id'];
            $orderDate  = Carbon::parse($data['date']);

            // Resolve every line's unit_price from the active agreement.
            $lines = [];
            $subtotal = 0.0;
            foreach ($data['items'] as $idx => $item) {
                $productId    = (int) $item['product_id'];
                $deliveryDate = Carbon::parse($item['delivery_date']);
                $qty          = (float) $item['quantity'];

                // Resolve at delivery_date — that's the date the price applies to.
                $agreement = $this->prices->resolve($customerId, $productId, $deliveryDate);
                $unitPrice = (float) $agreement->price;
                $lineTotal = round($qty * $unitPrice, 2);

                $lines[] = [
                    'product_id'         => $productId,
                    'quantity'           => $qty,
                    'unit_price'         => $unitPrice,
                    'total'              => $lineTotal,
                    'quantity_delivered' => 0,
                    'delivery_date'      => $deliveryDate->toDateString(),
                ];
                $subtotal += $lineTotal;
            }

            $vat   = round($subtotal * self::VAT_RATE, 2);
            $total = round($subtotal + $vat, 2);

            $so = SalesOrder::create([
                'so_number'          => $this->sequences->generate('sales_order'),
                'customer_id'        => $customerId,
                'date'               => $orderDate->toDateString(),
                'subtotal'           => $subtotal,
                'vat_amount'         => $vat,
                'total_amount'       => $total,
                'status'             => SalesOrderStatus::Draft->value,
                'payment_terms_days' => $data['payment_terms_days'] ?? 30,
                'delivery_terms'     => $data['delivery_terms'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'created_by'         => $userId,
            ]);

            // Persist lines.
            foreach ($lines as $line) {
                $so->items()->create($line);
            }

            return $this->show($so->fresh());
        });
    }

    /**
     * Update a draft SO (recreate line items). Disallowed past draft.
     */
    public function update(SalesOrder $so, array $data): SalesOrder
    {
        if ($so->status !== SalesOrderStatus::Draft) {
            throw new RuntimeException('Only draft sales orders can be updated.');
        }

        return DB::transaction(function () use ($so, $data) {
            $customerId = (int) ($data['customer_id'] ?? $so->customer_id);
            $subtotal = 0.0;
            $newLines = [];

            foreach ($data['items'] as $item) {
                $productId    = (int) $item['product_id'];
                $deliveryDate = Carbon::parse($item['delivery_date']);
                $qty          = (float) $item['quantity'];
                $agreement = $this->prices->resolve($customerId, $productId, $deliveryDate);
                $unitPrice = (float) $agreement->price;
                $lineTotal = round($qty * $unitPrice, 2);
                $newLines[] = [
                    'product_id'         => $productId,
                    'quantity'           => $qty,
                    'unit_price'         => $unitPrice,
                    'total'              => $lineTotal,
                    'quantity_delivered' => 0,
                    'delivery_date'      => $deliveryDate->toDateString(),
                ];
                $subtotal += $lineTotal;
            }
            $vat   = round($subtotal * self::VAT_RATE, 2);
            $total = round($subtotal + $vat, 2);

            $so->update([
                'customer_id'        => $customerId,
                'date'               => $data['date'] ?? $so->date->toDateString(),
                'subtotal'           => $subtotal,
                'vat_amount'         => $vat,
                'total_amount'       => $total,
                'payment_terms_days' => $data['payment_terms_days'] ?? $so->payment_terms_days,
                'delivery_terms'     => $data['delivery_terms'] ?? $so->delivery_terms,
                'notes'              => $data['notes'] ?? $so->notes,
            ]);

            // Replace items wholesale (draft state — no FK ramifications yet).
            $so->items()->delete();
            foreach ($newLines as $line) {
                $so->items()->create($line);
            }

            return $this->show($so->fresh());
        });
    }

    /**
     * Confirm a draft → 'confirmed'. Sprint 6 Task 52 will hook MRP run here.
     * For now we simply flip the status and invariant-check that the SO has lines.
     */
    public function confirm(SalesOrder $so): SalesOrder
    {
        if ($so->status !== SalesOrderStatus::Draft) {
            throw new RuntimeException('Only draft sales orders can be confirmed.');
        }
        if ($so->items()->count() === 0) {
            throw new RuntimeException('Cannot confirm a sales order with no items.');
        }
        return DB::transaction(function () use ($so) {
            $so->update(['status' => SalesOrderStatus::Confirmed->value]);

            // Sprint 6 Task 52: trigger MRP run synchronously inside the
            // confirmation transaction. Wrapped in try/catch so a broken BOM or
            // missing supplier mapping fails the whole confirm with a 422 — the
            // CRM officer has to fix the data before the SO can advance.
            $engine = $this->mrpEngine();
            if ($engine) {
                $engine->runForSalesOrder($so);
            }

            return $this->show($so->fresh());
        });
    }

    public function cancel(SalesOrder $so, ?string $reason = null): SalesOrder
    {
        if (! $so->is_cancellable) {
            throw new RuntimeException('This sales order cannot be cancelled at its current status.');
        }
        return DB::transaction(function () use ($so, $reason) {
            $so->update([
                'status' => SalesOrderStatus::Cancelled->value,
                'notes'  => trim(($so->notes ?? '') . "\n\n[Cancelled" . ($reason ? ': ' . $reason : '') . ']'),
            ]);
            // TODO Sprint 6 Task 52: cancel linked WOs + release reservations.
            return $this->show($so->fresh());
        });
    }

    public function delete(SalesOrder $so): void
    {
        if ($so->status !== SalesOrderStatus::Draft) {
            throw new RuntimeException('Only draft sales orders can be deleted.');
        }
        $so->delete();
    }

    /** Stubbed chain payload — Sprint 6 Tasks 49–58 fill it. */
    public function chain(SalesOrder $so): array
    {
        $confirmedDate = $so->status !== SalesOrderStatus::Draft ? $so->updated_at?->toDateString() : null;

        return [
            ['key' => 'order_entered', 'label' => 'Order Entered',
             'date' => $so->created_at?->toDateString(),
             'state' => 'done'],
            ['key' => 'mrp_planned', 'label' => 'MRP Planned',
             'date' => $confirmedDate,
             'state' => $so->mrp_plan_id ? 'done' : ($so->status === SalesOrderStatus::Confirmed ? 'active' : 'pending')],
            ['key' => 'in_production', 'label' => 'In Production',
             'date' => null,
             'state' => $so->status === SalesOrderStatus::InProduction ? 'active' : 'pending'],
            ['key' => 'qc_outgoing', 'label' => 'QC Outgoing', 'date' => null, 'state' => 'pending'],
            ['key' => 'delivered', 'label' => 'Delivered', 'date' => null,
             'state' => in_array($so->status, [SalesOrderStatus::Delivered, SalesOrderStatus::Invoiced], true) ? 'done' : 'pending'],
            ['key' => 'invoiced', 'label' => 'Invoiced', 'date' => null,
             'state' => $so->status === SalesOrderStatus::Invoiced ? 'done' : 'pending'],
        ];
    }
}
