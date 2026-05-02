<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BillService
{
    /** Standard PH VAT rate. */
    private const VAT_RATE = '0.12';

    /** AP control account code. */
    private const AP_CODE      = '2010';
    private const VAT_INPUT    = '1310';

    public function __construct(
        private readonly JournalEntryService $journals,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Bill::query()->with(['vendor:id,name']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $vendorId = HashIdFilter::decode($filters['vendor_id'], Vendor::class);
            if ($vendorId) $q->where('vendor_id', $vendorId);
        }
        if (! empty($filters['from'])) $q->whereDate('date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('date', '<=', $filters['to']);
        if (! empty($filters['overdue'])) {
            $q->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
              ->whereDate('due_date', '<', now());
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('bill_number', 'ilike', "%{$term}%")
                   ->orWhereHas('vendor', fn ($vv) => $vv->where('name', 'ilike', "%{$term}%"));
            });
        }

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Bill $bill): Bill
    {
        return $bill->load([
            'vendor',
            'items.expenseAccount:id,code,name',
            'payments.cashAccount:id,code,name',
            'journalEntry:id,entry_number,date,status',
            // role_id required so User's $with=['role'] eager-load can resolve.
            'creator:id,name,role_id',
        ]);
    }

    /**
     * Create a bill, build a balanced JE, post it immediately, link to bill.
     */
    public function create(array $data, User $by): Bill
    {
        return DB::transaction(function () use ($data, $by) {
            $vendor = Vendor::findOrFail(
                HashIdFilter::decode($data['vendor_id'], Vendor::class)
            );
            $isVatable = (bool) ($data['is_vatable'] ?? true);

            // Build items + totals.
            [$items, $subtotal] = $this->normalizeItems($data['items'] ?? []);
            $vat = $isVatable ? Money::mul($subtotal, self::VAT_RATE) : Money::zero();
            $total = Money::add($subtotal, $vat);

            // Vendor uniqueness on bill_number.
            $exists = Bill::query()
                ->where('vendor_id', $vendor->id)
                ->where('bill_number', $data['bill_number'])
                ->exists();
            if ($exists) {
                throw new RuntimeException("Bill number '{$data['bill_number']}' already exists for this vendor.");
            }

            $bill = Bill::create([
                'bill_number'   => $data['bill_number'],
                'vendor_id'     => $vendor->id,
                'date'          => $data['date'],
                'due_date'      => $data['due_date']
                    ?? Carbon::parse($data['date'])->addDays($vendor->payment_terms_days)->toDateString(),
                'is_vatable'    => $isVatable,
                'subtotal'      => $subtotal,
                'vat_amount'    => $vat,
                'total_amount'  => $total,
                'amount_paid'   => Money::zero(),
                'balance'       => $total,
                'status'        => BillStatus::Unpaid,
                'created_by'    => $by->id,
                'remarks'       => $data['remarks'] ?? null,
            ]);

            foreach ($items as $row) {
                BillItem::create(array_merge($row, ['bill_id' => $bill->id]));
            }

            // Build JE: DR each expense_account_id; DR VAT Input if vatable; CR AP.
            $apId      = $this->accountId(self::AP_CODE);
            $vatInputId = $this->accountId(self::VAT_INPUT);

            $lines = [];
            foreach ($items as $row) {
                $lines[] = [
                    'account_id' => $row['expense_account_id'],
                    'debit'      => $row['total'],
                    'credit'     => '0.00',
                    'description'=> $row['description'],
                ];
            }
            if ($isVatable && Money::gt($vat, '0')) {
                $lines[] = [
                    'account_id' => $vatInputId,
                    'debit'      => $vat,
                    'credit'     => '0.00',
                    'description'=> 'VAT Input',
                ];
            }
            $lines[] = [
                'account_id' => $apId,
                'debit'      => '0.00',
                'credit'     => $total,
                'description'=> "AP — {$vendor->name} · {$data['bill_number']}",
            ];

            $je = $this->journals->create([
                'date'           => (string) $bill->date->toDateString(),
                'description'    => "Bill {$bill->bill_number} from {$vendor->name}",
                'reference_type' => 'bill',
                'reference_id'   => $bill->id,
                'lines'          => $lines,
            ], $by);
            $je = $this->journals->post($je, $by);

            $bill->update(['journal_entry_id' => $je->id]);

            return $this->show($bill->fresh());
        });
    }

    public function cancel(Bill $bill, User $by): Bill
    {
        if (! Money::isZero((string) $bill->amount_paid)) {
            throw new RuntimeException('Cannot cancel a bill that has payments.');
        }
        if ($bill->status === BillStatus::Cancelled) {
            return $bill;
        }

        return DB::transaction(function () use ($bill, $by) {
            // Reverse the original JE if posted.
            if ($bill->journal_entry_id) {
                $je = $bill->journalEntry;
                if ($je && $je->status === JournalEntryStatus::Posted) {
                    $this->journals->reverse($je, $by);
                }
            }
            $bill->update([
                'status'  => BillStatus::Cancelled,
                'balance' => Money::zero(),
            ]);
            return $bill->fresh();
        });
    }

    /**
     * Record a payment against an open bill.
     */
    public function recordPayment(Bill $bill, array $data, User $by): BillPayment
    {
        if ($bill->status === BillStatus::Cancelled) {
            throw new RuntimeException('Cannot record payment on a cancelled bill.');
        }
        if ($bill->status === BillStatus::Paid) {
            throw new RuntimeException('Bill is already fully paid.');
        }

        $amount = Money::round2((string) $data['amount']);
        if (Money::lte($amount, '0')) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }
        if (Money::gt($amount, (string) $bill->balance)) {
            throw new RuntimeException("Payment {$amount} exceeds outstanding balance " . $bill->balance . '.');
        }

        return DB::transaction(function () use ($bill, $data, $amount, $by) {
            $cashAccountId = HashIdFilter::decode($data['cash_account_id'], Account::class);
            if (! $cashAccountId) {
                throw new RuntimeException('Invalid cash account.');
            }

            $payment = BillPayment::create([
                'bill_id'           => $bill->id,
                'cash_account_id'   => $cashAccountId,
                'payment_date'      => $data['payment_date'],
                'amount'            => $amount,
                'payment_method'    => $data['payment_method'],
                'reference_number'  => $data['reference_number'] ?? null,
                'created_by'        => $by->id,
            ]);

            $apId = $this->accountId(self::AP_CODE);
            $je = $this->journals->create([
                'date'           => $payment->payment_date->toDateString(),
                'description'    => "Payment for Bill {$bill->bill_number}",
                'reference_type' => 'bill_payment',
                'reference_id'   => $payment->id,
                'lines'          => [
                    ['account_id' => $apId,           'debit' => $amount, 'credit' => '0.00', 'description' => 'AP settled'],
                    ['account_id' => $cashAccountId,  'debit' => '0.00',  'credit' => $amount, 'description' => 'Cash disbursed'],
                ],
            ], $by);
            $je = $this->journals->post($je, $by);

            $payment->update(['journal_entry_id' => $je->id]);

            // Update bill totals.
            $newPaid    = Money::add((string) $bill->amount_paid, $amount);
            $newBalance = Money::sub((string) $bill->total_amount, $newPaid);
            $newStatus  = Money::isZero($newBalance) ? BillStatus::Paid : BillStatus::Partial;

            $bill->update([
                'amount_paid' => $newPaid,
                'balance'     => $newBalance,
                'status'      => $newStatus,
            ]);

            return $payment->fresh(['cashAccount']);
        });
    }

    /**
     * Aging buckets for AP — used by Tasks 35/37.
     *
     * @return array{
     *   buckets: array{current: string, d1_30: string, d31_60: string, d61_90: string, d91_plus: string, total: string},
     *   by_vendor: array<int, array>
     * }
     */
    public function aging(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? now();

        $rows = Bill::query()
            ->with('vendor:id,name')
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->orderBy('vendor_id')
            ->get();

        $buckets = ['current' => '0.00', 'd1_30' => '0.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'd91_plus' => '0.00', 'total' => '0.00'];
        $byVendor = [];

        foreach ($rows as $bill) {
            $bucket = $bill->agingBucket($asOf);
            $balance = (string) $bill->balance;
            $buckets[$bucket] = Money::add($buckets[$bucket], $balance);
            $buckets['total'] = Money::add($buckets['total'], $balance);

            $vid = $bill->vendor_id;
            if (! isset($byVendor[$vid])) {
                $byVendor[$vid] = [
                    'vendor_id'   => $bill->vendor->hash_id,
                    'vendor_name' => $bill->vendor->name,
                    'current'     => '0.00',
                    'd1_30'       => '0.00',
                    'd31_60'      => '0.00',
                    'd61_90'      => '0.00',
                    'd91_plus'    => '0.00',
                    'total'       => '0.00',
                ];
            }
            $byVendor[$vid][$bucket] = Money::add($byVendor[$vid][$bucket], $balance);
            $byVendor[$vid]['total'] = Money::add($byVendor[$vid]['total'], $balance);
        }

        return ['buckets' => $buckets, 'by_vendor' => array_values($byVendor)];
    }

    public function openBalance(Vendor $vendor): string
    {
        return (string) Bill::query()
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->sum('balance');
    }

    /**
     * @return array{0: array<int, array{expense_account_id:int, description:string, quantity:string, unit:?string, unit_price:string, total:string}>, 1: string}
     */
    private function normalizeItems(array $rawItems): array
    {
        if (count($rawItems) === 0) {
            throw new RuntimeException('A bill must have at least one line item.');
        }

        $rows = []; $subtotal = Money::zero();
        foreach ($rawItems as $raw) {
            $accountId = HashIdFilter::decode($raw['expense_account_id'] ?? null, Account::class);
            if (! $accountId) {
                throw new RuntimeException('Invalid expense_account_id on bill item.');
            }

            $qty   = Money::round2((string) $raw['quantity']);
            $price = Money::round2((string) $raw['unit_price']);
            $total = Money::round2(bcmul($qty, $price, 4));

            if (Money::lte($qty, '0') || Money::lt($price, '0')) {
                throw new RuntimeException('Quantity must be > 0, unit price must be ≥ 0.');
            }

            $rows[] = [
                'expense_account_id' => $accountId,
                'description'        => (string) $raw['description'],
                'quantity'           => $qty,
                'unit'               => $raw['unit'] ?? null,
                'unit_price'         => $price,
                'total'              => $total,
            ];
            $subtotal = Money::add($subtotal, $total);
        }
        return [$rows, $subtotal];
    }

    private function accountId(string $code): int
    {
        $id = Account::query()->where('code', $code)->value('id');
        if (! $id) {
            throw new RuntimeException("Required account {$code} not found in COA. Run ChartOfAccountsSeeder.");
        }
        return (int) $id;
    }
}
