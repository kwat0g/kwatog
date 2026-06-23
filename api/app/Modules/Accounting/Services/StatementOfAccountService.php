<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Collection;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use Carbon\Carbon;

/**
 * Read-only report service that generates a per-customer Statement of Account
 * with opening balance, transaction-ledger detail, running balance, and aging.
 */
class StatementOfAccountService
{
    /**
     * Generate a statement of account for a given customer as of a date.
     *
     * @return array{
     *     customer: array{id: string, name: string, code: string},
     *     as_of: string,
     *     currency: string,
     *     opening_balance: string,
     *     transactions: list<array{date: string, type: string, reference: string, description: string, amount: string, running_balance: string}>,
     *     closing_balance: string,
     *     aging: array{current: string, d30_days: string, d60_days: string, d90_plus: string},
     *     total_outstanding: string,
     * }
     */
    public function forCustomer(Customer $customer, ?string $asOf = null): array
    {
        $asOfDate = $asOf ? Carbon::parse($asOf) : now();

        $transactions = $this->buildTransactions($customer, $asOfDate);

        $openingBalance = Money::zero();
        $running = Money::zero();

        $entries = [];
        foreach ($transactions as $txn) {
            $running = Money::add($running, $txn['amount']);
            if ($txn['cutoff_date']->lt($asOfDate->copy()->startOfDay())) {
                $openingBalance = Money::add($openingBalance, $txn['amount']);
            }
            $entries[] = [
                'date'             => $txn['date'],
                'type'             => $txn['type'],
                'reference'        => $txn['reference'],
                'description'      => $txn['description'],
                'amount'           => $txn['amount'],
                'running_balance'  => $running,
            ];
        }

        // Entries on or before asOfDate are included; entries strictly after are excluded.
        $visibleEntries = array_values(array_filter($entries, fn ($e) => $e['date'] <= $asOfDate->toDateString()));

        $closingBalance = count($visibleEntries) > 0
            ? $visibleEntries[count($visibleEntries) - 1]['running_balance']
            : Money::zero();

        // Aging: compute from open (unpaid) invoices only.
        $aging = $this->computeAging($customer, $asOfDate);
        $totalOutstanding = Money::add($aging['current'], $aging['d30_days'], $aging['d60_days'], $aging['d90_plus']);

        return [
            'customer' => [
                'id'   => $customer->hash_id,
                'name' => $customer->name,
                'code' => $customer->code,
            ],
            'as_of'            => $asOfDate->toDateString(),
            'currency'         => 'PHP',
            'opening_balance'  => $openingBalance,
            'transactions'     => $visibleEntries,
            'closing_balance'  => $closingBalance,
            'aging'            => $aging,
            'total_outstanding' => $totalOutstanding,
        ];
    }

    /**
     * @return list<array{date: string, type: string, reference: string, description: string, amount: string, cutoff_date: Carbon}>
     */
    private function buildTransactions(Customer $customer, Carbon $asOfDate): array
    {
        $txns = [];

        // --- Invoice postings (positive amounts) ---
        $invoices = Invoice::where('customer_id', $customer->id)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->whereDate('date', '<=', $asOfDate->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'date', 'total_amount', 'status']);

        foreach ($invoices as $inv) {
            $txns[] = [
                'date'        => $inv->date->toDateString(),
                'type'        => 'invoice',
                'reference'   => $inv->invoice_number ?? ('INV-' . $inv->hash_id),
                'description' => match (true) {
                    $inv->status === InvoiceStatus::Paid     => 'Sales invoice (paid)',
                    $inv->status === InvoiceStatus::Partial  => 'Sales invoice (partial)',
                    default                                  => 'Sales invoice',
                },
                'amount'      => (string) $inv->total_amount,
                'cutoff_date' => $inv->date,
            ];
        }

        // --- Collections (payments received — negative amounts) ---
        $collections = Collection::query()
            ->whereHas('invoice', fn ($q) => $q->where('customer_id', $customer->id)
                ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            )
            ->whereDate('collection_date', '<=', $asOfDate->toDateString())
            ->orderBy('collection_date')
            ->orderBy('id')
            ->get(['id', 'invoice_id', 'collection_date', 'amount', 'reference_number']);

        foreach ($collections as $coll) {
            $ref = $coll->reference_number ?? ('COL-' . $coll->hash_id);
            $txns[] = [
                'date'        => $coll->collection_date->toDateString(),
                'type'        => 'payment',
                'reference'   => $ref,
                'description' => 'Payment received',
                'amount'      => Money::negate((string) $coll->amount),
                'cutoff_date' => $coll->collection_date,
            ];
        }

        // Sort by date then by cutoff_date timestamp for deterministic order
        usort($txns, fn (array $a, array $b): int => ($a['date'] <=> $b['date'])
            ?: ($a['cutoff_date']->timestamp <=> $b['cutoff_date']->timestamp)
            ?: 0);

        return $txns;
    }

    /**
     * @return array{current: string, d30_days: string, d60_days: string, d90_plus: string}
     */
    private function computeAging(Customer $customer, Carbon $asOfDate): array
    {
        $buckets = [
            'current' => Money::zero(),
            'd30_days' => Money::zero(),
            'd60_days' => Money::zero(),
            'd90_plus' => Money::zero(),
        ];

        $invoices = Invoice::where('customer_id', $customer->id)
            ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial])
            ->whereDate('date', '<=', $asOfDate->toDateString())
            ->get(['balance', 'due_date']);

        foreach ($invoices as $inv) {
            $balance = (string) $inv->balance;
            if (Money::isZero($balance)) {
                continue;
            }

            $dueDate = $inv->due_date;
            if (! $dueDate || $dueDate->gte($asOfDate)) {
                $buckets['current'] = Money::add($buckets['current'], $balance);
                continue;
            }

            $daysOverdue = $dueDate->diffInDays($asOfDate);

            if ($daysOverdue <= 30) {
                $buckets['d30_days'] = Money::add($buckets['d30_days'], $balance);
            } elseif ($daysOverdue <= 60) {
                $buckets['d60_days'] = Money::add($buckets['d60_days'], $balance);
            } else {
                $buckets['d90_plus'] = Money::add($buckets['d90_plus'], $balance);
            }
        }

        return $buckets;
    }
}
