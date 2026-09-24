<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillPaymentStatus;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Models\BillPayment;
use Illuminate\Support\Facades\DB;

/**
 * BIR Form 2307 — Certificate of Expanded Withholding Tax at Source
 * Summarizes EWT withheld from a vendor's payments during a quarter.
 */
class Bir2307Service
{
    public function __construct(
        private readonly \App\Common\Services\SettingsService $settings,
    ) {}

    /**
     * Generate BIR 2307 data for a vendor for a given quarter.
     * Includes all POSTED bill payments with EWT > 0 in that quarter.
     *
     * @return array{
     *     vendor: array{id: string, name: string, tin: string, address: string},
     *     payor: array{name: string, tin: string, address: string},
     *     year: int,
     *     quarter: int,
     *     income_payments: array<int, array{
     *         month: int,
     *         gross_amount: string,
     *         tax_withheld: string,
     *         atc: string
     *     }>,
     *     summary: array{
     *         total_gross: string,
     *         total_tax_withheld: string
     *     }
     * }
     */
    public function forVendorQuarter(Vendor $vendor, int $year, int $quarter): array
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new \InvalidArgumentException('Quarter must be between 1 and 4.');
        }

        // Determine month range for the quarter
        $monthStart = ($quarter - 1) * 3 + 1;
        $monthEnd = $monthStart + 2;

        // Fetch POSTED bill payments with EWT > 0 in this quarter
        $payments = BillPayment::query()
            ->join('bills', 'bill_payments.bill_id', '=', 'bills.id')
            ->where('bills.vendor_id', $vendor->id)
            ->where('bill_payments.status', BillPaymentStatus::Posted->value)
            ->whereYear('bill_payments.payment_date', $year)
            ->whereBetween(DB::raw('EXTRACT(MONTH FROM bill_payments.payment_date)'), [$monthStart, $monthEnd])
            ->where('bill_payments.ewt_amount', '>', 0)
            ->orderBy('bill_payments.payment_date')
            ->get([
                'bill_payments.id',
                'bill_payments.amount',
                'bill_payments.ewt_amount',
                'bill_payments.payment_date',
                'bills.withholding_tax_type',
                'bills.total_amount',
                'bills.subtotal',
            ]);

        // Group by month and ATC
        $incomePaymentsByMonth = [];
        foreach ($payments as $payment) {
            $month = $payment->payment_date->month;

            // Calculate gross amount net of VAT portion
            // gross_for_form = amount × subtotal / total_amount. Multiply before
            // dividing: the ratio alone truncates to 4 dp (79200/88704 → 0.8928),
            // which understated a ₱39,600.00 base as ₱39,597.47.
            $grossIncome = Money::round2(Money::div(
                bcmul((string) $payment->amount, (string) $payment->subtotal, Money::INNER),
                (string) $payment->total_amount,
                8,
            ));

            if (! isset($incomePaymentsByMonth[$month])) {
                $incomePaymentsByMonth[$month] = [];
            }

            $atc = \App\Modules\Accounting\Enums\WithholdingTaxType::from($payment->withholding_tax_type)->atc();

            if (! isset($incomePaymentsByMonth[$month][$atc])) {
                $incomePaymentsByMonth[$month][$atc] = [
                    'gross_amount' => Money::zero(),
                    'tax_withheld' => Money::zero(),
                ];
            }

            $incomePaymentsByMonth[$month][$atc]['gross_amount'] = Money::add(
                (string) $incomePaymentsByMonth[$month][$atc]['gross_amount'],
                $grossIncome
            );
            $incomePaymentsByMonth[$month][$atc]['tax_withheld'] = Money::add(
                (string) $incomePaymentsByMonth[$month][$atc]['tax_withheld'],
                (string) $payment->ewt_amount
            );
        }

        // Flatten to array of income payments
        $incomePayments = [];
        $totalGross = Money::zero();
        $totalTaxWithheld = Money::zero();

        for ($month = $monthStart; $month <= $monthEnd; $month++) {
            if (! isset($incomePaymentsByMonth[$month])) {
                continue;
            }

            foreach ($incomePaymentsByMonth[$month] as $atc => $data) {
                $incomePayments[] = [
                    'month' => $month,
                    'gross_amount' => $data['gross_amount'],
                    'tax_withheld' => $data['tax_withheld'],
                    'atc' => $atc,
                ];

                $totalGross = Money::add($totalGross, (string) $data['gross_amount']);
                $totalTaxWithheld = Money::add($totalTaxWithheld, (string) $data['tax_withheld']);
            }
        }

        return [
            'vendor' => [
                'id' => $vendor->hash_id,
                'name' => $vendor->name,
                'tin' => $vendor->tin ?? '(Not provided)',
                'address' => $vendor->address ?? '(Not provided)',
            ],
            'payor' => [
                'name' => $this->settings->get('company.legal_name', 'Philippine Ogami Corporation'),
                'tin' => $this->settings->get('company.tin', ''),
                'address' => $this->settings->get('company.address', ''),
            ],
            'year' => $year,
            'quarter' => $quarter,
            'income_payments' => $incomePayments,
            'summary' => [
                'total_gross' => $totalGross,
                'total_tax_withheld' => $totalTaxWithheld,
            ],
        ];
    }
}
