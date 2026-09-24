<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\TaxPolicyService;
use App\Common\Support\Money;
use App\Modules\Purchasing\Enums\SupplierQuoteResponseStatus;
use App\Modules\Purchasing\Enums\VatTreatment;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use Illuminate\Support\Collection;

/**
 * The one place RFQ quotation money is computed: the quote's own totals, the
 * comparison's per-line delivered cost, and the figures a generated PO carries.
 * All three share one rule — freight and VAT follow goods value — so the
 * comparison a buyer awards on is exactly what the PO totals.
 *
 * A quote states VAT one of three ways:
 *   exclusive  VAT = (goods + freight) × rate, added on top
 *   inclusive  prices and freight already contain VAT = base × rate ÷ (1 + rate)
 *   none       supplier is not VAT-registered; VAT = 0
 */
class RfqCommercialCalculator
{
    public function __construct(private readonly TaxPolicyService $taxPolicy) {}

    /** Line goods value: offered quantity × unit price, to the centavo. */
    public function lineGoods(?string $quantity, ?string $unitPrice): string
    {
        if ($quantity === null || $unitPrice === null) {
            return Money::zero();
        }

        return Money::round2(bcmul($quantity, $unitPrice, 8));
    }

    /**
     * @param  iterable<SupplierQuoteItem>  $lines
     */
    public function goods(iterable $lines): string
    {
        $total = Money::zero();
        foreach ($lines as $line) {
            if ($this->isQuoted($line)) {
                $total = Money::add($total, $this->lineGoods((string) $line->offered_quantity, (string) $line->unit_price));
            }
        }

        return $total;
    }

    /** @return array{vat: string, total: string} */
    public function totals(VatTreatment $treatment, string $goods, string $freight): array
    {
        $base = Money::add($goods, $freight);
        if ($treatment === VatTreatment::None) {
            return ['vat' => Money::zero(), 'total' => $base];
        }
        $rate = $this->taxPolicy->requiredVatRate();
        if ($treatment === VatTreatment::Inclusive) {
            return ['vat' => Money::round2(bcdiv(bcmul($base, $rate, 8), bcadd('1', $rate, 8), 8)), 'total' => $base];
        }
        $vat = Money::round2(bcmul($base, $rate, 8));

        return ['vat' => $vat, 'total' => Money::add($base, $vat)];
    }

    /**
     * Each quoted line's delivered cost: its goods plus its goods-value share
     * of the quote's freight and (for VAT-exclusive quotes) VAT.
     *
     * @return array<int, string> supplier_quote_item_id => allocated delivered cost
     */
    public function allocate(SupplierQuote $quote): array
    {
        $quote->loadMissing('items');
        $quoted = $quote->items->filter(fn (SupplierQuoteItem $line): bool => $this->isQuoted($line));
        $goods = $this->goods($quoted);
        $onTop = Money::add(
            (string) $quote->freight_amount,
            $quote->vat_treatment === VatTreatment::Exclusive ? (string) $quote->vat_amount : '0',
        );
        $allocated = [];
        foreach ($quoted as $line) {
            $lineGoods = $this->lineGoods((string) $line->offered_quantity, (string) $line->unit_price);
            $allocated[(int) $line->id] = Money::add($lineGoods, $this->share($onTop, $lineGoods, $goods));
        }

        return $allocated;
    }

    /**
     * Each quoted line's cost net of VAT: goods plus freight share, with VAT
     * backed out of an inclusive quote. A VAT-registered buyer recovers input
     * VAT, so this — not the gross — is what a line really costs Ogami.
     *
     * @return array<int, string> supplier_quote_item_id => net cost
     */
    public function allocateNet(SupplierQuote $quote): array
    {
        $quote->loadMissing('items');
        $quoted = $quote->items->filter(fn (SupplierQuoteItem $line): bool => $this->isQuoted($line));
        $goods = $this->goods($quoted);
        $divisor = $quote->vat_treatment === VatTreatment::Inclusive
            ? bcadd('1', $this->taxPolicy->requiredVatRate(), 8)
            : '1';
        $net = [];
        foreach ($quoted as $line) {
            $lineGoods = $this->lineGoods((string) $line->offered_quantity, (string) $line->unit_price);
            $gross = Money::add($lineGoods, $this->share((string) $quote->freight_amount, $lineGoods, $goods));
            $net[(int) $line->id] = Money::round2(bcdiv($gross, $divisor, 8));
        }

        return $net;
    }

    /**
     * Figures for the draft PO of one winning quote, carrying only the awarded
     * lines. Freight follows the awarded goods share. An inclusive quote is
     * split into net prices and net freight plus the VAT that makes the PO
     * total equal the gross quoted for those lines.
     *
     * @param  Collection<int, SupplierQuoteItem>  $awardedLines
     * @return array{header: array{vat_amount: string, freight_amount: string, other_charges: string}, unit_prices: array<int, string>, total: string}
     */
    public function forPurchaseOrder(SupplierQuote $quote, Collection $awardedLines): array
    {
        $quote->loadMissing('items');
        $quotedGoods = $this->goods($quote->items);
        $awardedGoods = $this->goods($awardedLines);
        $freight = $this->share((string) $quote->freight_amount, $awardedGoods, $quotedGoods);
        $treatment = $quote->vat_treatment;
        $unitPrices = [];

        if ($treatment === VatTreatment::Inclusive) {
            $divisor = bcadd('1', $this->taxPolicy->requiredVatRate(), 8);
            $netGoods = Money::zero();
            foreach ($awardedLines as $line) {
                $net = bcdiv((string) $line->unit_price, $divisor, 4);
                $unitPrices[(int) $line->id] = $net;
                $netGoods = Money::add($netGoods, $this->lineGoods((string) $line->offered_quantity, $net));
            }
            $netFreight = Money::round2(bcdiv($freight, $divisor, 8));
            $gross = Money::add($awardedGoods, $freight);
            $vat = Money::sub($gross, Money::add($netGoods, $netFreight));

            return [
                'header' => ['vat_amount' => $vat, 'freight_amount' => $netFreight, 'other_charges' => Money::zero()],
                'unit_prices' => $unitPrices,
                'total' => $gross,
            ];
        }

        foreach ($awardedLines as $line) {
            $unitPrices[(int) $line->id] = (string) $line->unit_price;
        }
        $vat = $this->totals($treatment, $awardedGoods, $freight)['vat'];

        return [
            'header' => ['vat_amount' => $vat, 'freight_amount' => $freight, 'other_charges' => Money::zero()],
            'unit_prices' => $unitPrices,
            'total' => Money::add($awardedGoods, $freight, $vat),
        ];
    }

    private function share(string $amount, string $part, string $whole): string
    {
        if (Money::isZero($amount)) {
            return Money::zero();
        }
        if (Money::isZero($whole)) {
            return Money::isZero($part) ? Money::zero() : Money::round2($amount);
        }

        // Multiply before dividing so a one-third share keeps its centavos.
        return Money::round2(bcdiv(bcmul($amount, $part, 8), $whole, 8));
    }

    private function isQuoted(SupplierQuoteItem $line): bool
    {
        return $line->response_status === SupplierQuoteResponseStatus::Quoted;
    }
}
