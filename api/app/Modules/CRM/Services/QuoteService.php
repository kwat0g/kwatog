<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Enums\QuoteStatus;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QuoteService
{
    /** Philippines VAT rate — mirrors SalesOrderService::VAT_RATE. */
    private const VAT_RATE = 0.12;

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SalesOrderService $salesOrderService,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Quote::query()
            ->with(['customer:id,name', 'opportunity:id,opportunity_number,title'])
            ->withCount('items');

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) $q->where('customer_id', $cid);
        }
        if (! empty($filters['opportunity_id'])) {
            $oid = HashIdFilter::decode($filters['opportunity_id'], Opportunity::class);
            if ($oid) $q->where('opportunity_id', $oid);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('quote_number', SearchOperator::like(), "%{$term}%")
                   ->orWhereHas('customer', fn ($c) => $c->where('name', SearchOperator::like(), "%{$term}%"));
            });
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Quote $quote): Quote
    {
        return $quote->load([
            'customer:id,name',
            'opportunity:id,opportunity_number,title',
            'items.product:id,part_number,name,unit_of_measure',
        ]);
    }

    public function create(array $data): Quote
    {
        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            [$subtotal, $tax, $total] = $this->computeTotals($items);

            $quote = Quote::create([
                'quote_number'  => $this->sequences->generate('quote'),
                'opportunity_id'=> $data['opportunity_id'] ?? null,
                'customer_id'   => (int) $data['customer_id'],
                'status'        => QuoteStatus::Draft->value,
                'valid_until'   => $data['valid_until'] ?? null,
                'subtotal'      => $subtotal,
                'tax_amount'    => $tax,
                'total_amount'  => $total,
                'terms'         => $data['terms'] ?? null,
                'revision'      => 1,
            ]);

            $this->syncItems($quote, $items);

            return $this->show($quote->fresh());
        });
    }

    /**
     * Called by OpportunityService::createQuote — seeds a draft Quote from the Opportunity.
     */
    public function createFromOpportunity(Opportunity $opportunity): Quote
    {
        return DB::transaction(function () use ($opportunity) {
            $quote = Quote::create([
                'quote_number'   => $this->sequences->generate('quote'),
                'opportunity_id' => $opportunity->id,
                'customer_id'    => $opportunity->customer_id,
                'status'         => QuoteStatus::Draft->value,
                'subtotal'       => '0.00',
                'tax_amount'     => '0.00',
                'total_amount'   => '0.00',
                'revision'       => 1,
            ]);
            return $this->show($quote->fresh());
        });
    }

    public function update(Quote $quote, array $data): Quote
    {
        if (! $quote->status->isEditable()) {
            throw new RuntimeException('Only draft quotes can be updated.');
        }

        return DB::transaction(function () use ($quote, $data) {
            $items = $data['items'] ?? null;

            if ($items !== null) {
                [$subtotal, $tax, $total] = $this->computeTotals($items);
            } else {
                $subtotal = $quote->subtotal;
                $tax      = $quote->tax_amount;
                $total    = $quote->total_amount;
            }

            $quote->update([
                'customer_id'  => $data['customer_id'] ?? $quote->customer_id,
                'valid_until'  => $data['valid_until'] ?? $quote->valid_until?->toDateString(),
                'subtotal'     => $subtotal,
                'tax_amount'   => $tax,
                'total_amount' => $total,
                'terms'        => $data['terms'] ?? $quote->terms,
            ]);

            if ($items !== null) {
                $quote->items()->delete();
                $this->syncItems($quote, $items);
            }

            return $this->show($quote->fresh());
        });
    }

    public function send(Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::Draft) {
            throw new RuntimeException('Only draft quotes can be sent.');
        }
        $quote->status = QuoteStatus::Sent;
        $quote->save();
        return $this->show($quote->fresh());
    }

    public function accept(Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::Sent) {
            throw new RuntimeException('Only sent quotes can be accepted.');
        }
        $quote->status = QuoteStatus::Accepted;
        $quote->save();
        return $this->show($quote->fresh());
    }

    public function reject(Quote $quote): Quote
    {
        if (! in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Sent], true)) {
            throw new RuntimeException('Only draft or sent quotes can be rejected.');
        }
        $quote->status = QuoteStatus::Rejected;
        $quote->save();
        return $this->show($quote->fresh());
    }

    /**
     * Convert an accepted (or sent) Quote into a SalesOrder.
     *
     * Mapping to SalesOrderService::create():
     *  - customer_id  → quote.customer_id (raw integer)
     *  - date         → today
     *  - items[].product_id   → quote_item.product_id (raw integer)
     *  - items[].quantity     → quote_item.quantity
     *  - items[].delivery_date → quote.valid_until ?? today+30 days (one per line)
     *  - notes        → quote.terms
     *
     * SalesOrderService resolves prices via PriceAgreementService internally;
     * the quote's unit_price is informational — SO will re-price from agreements.
     *
     * Sets quote.status → converted and quote.converted_to_sales_order_id.
     */
    public function convertToSalesOrder(Quote $quote, int $userId): SalesOrder
    {
        if (! in_array($quote->status, [QuoteStatus::Accepted, QuoteStatus::Sent], true)) {
            throw new RuntimeException('Only accepted or sent quotes can be converted to a sales order.');
        }
        if ($quote->converted_to_sales_order_id) {
            throw new RuntimeException('This quote has already been converted to a sales order.');
        }
        if ($quote->items()->count() === 0) {
            throw new RuntimeException('Cannot convert a quote with no line items.');
        }

        return DB::transaction(function () use ($quote, $userId) {
            // Default delivery date: valid_until or today + 30 days.
            $deliveryDate = $quote->valid_until
                ? $quote->valid_until->toDateString()
                : Carbon::today()->addDays(30)->toDateString();

            $quote->load('items');
            $soData = [
                'customer_id'        => $quote->customer_id,
                'date'               => Carbon::today()->toDateString(),
                'notes'              => $quote->terms,
                'payment_terms_days' => 30,
                'items'              => $quote->items->map(fn ($item) => [
                    'product_id'    => $item->product_id,
                    'quantity'      => (float) $item->quantity,
                    'delivery_date' => $deliveryDate,
                ])->toArray(),
            ];

            // SalesOrderService::create() resolves prices from PriceAgreementService,
            // generates so_number, and wraps everything in its own transaction (nested OK in PG).
            $salesOrder = $this->salesOrderService->create($soData, $userId);

            // Link the quote to the resulting SO and mark converted.
            $quote->converted_to_sales_order_id = $salesOrder->id;
            $quote->status = QuoteStatus::Converted;
            $quote->save();

            return $salesOrder;
        });
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function computeTotals(array $items): array
    {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty       = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $subtotal += round($qty * $unitPrice, 2);
        }
        $tax   = round($subtotal * self::VAT_RATE, 2);
        $total = round($subtotal + $tax, 2);
        return [$subtotal, $tax, $total];
    }

    private function syncItems(Quote $quote, array $items): void
    {
        foreach ($items as $item) {
            $qty       = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $lineTotal = round($qty * $unitPrice, 2);

            $quote->items()->create([
                'product_id'  => (int) $item['product_id'],
                'quantity'    => $qty,
                'unit_price'  => $unitPrice,
                'line_total'  => $lineTotal,
                'description' => $item['description'] ?? null,
            ]);
        }
    }
}
