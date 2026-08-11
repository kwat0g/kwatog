<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\WidgetScope;
use App\Modules\Loans\Enums\LoanStatus;
use Illuminate\Support\Facades\DB;

/**
 * Loan analytics. Scoped exactly like the scalar path: company-wide only
 * under `loans.write_off`, otherwise the caller's own department — mirroring
 * LoanController's department filter. A company-wide table here would hand
 * department_head figures its own module refuses it.
 */
final class LoanWidgetAnalytics
{
    public function __construct(private readonly WidgetScope $scope) {}

    /** @return list<string> */
    public function handles(): array
    {
        return ['loans.outstanding'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'loans.outstanding') {
            return [];
        }

        $companyWide = $this->scope->isCompanyWide($user, 'loans.write_off');
        $departmentId = $this->scope->departmentId($user);

        // Not company-wide and no department to fall back to: show nothing
        // rather than everything.
        if (! $companyWide && $departmentId === null) {
            return [];
        }

        $base = fn () => DB::table('employee_loans as l')
            ->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->whereIn('l.status', [LoanStatus::Active->value, LoanStatus::Pending->value])
            ->when(! $companyWide, fn ($q) => $q->where('e.department_id', $departmentId));

        $rows = $base()
            ->selectRaw("e.first_name || ' ' || e.last_name as borrower, l.loan_type, l.balance")
            ->orderByDesc('l.balance')
            ->limit(10)
            ->get();

        return [
            'columns' => [
                ['key' => 'borrower', 'label' => 'Borrower', 'align' => 'left'],
                ['key' => 'loan_type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'balance', 'label' => 'Balance', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn ($r) => [
                'borrower' => (string) $r->borrower,
                'loan_type' => (string) $r->loan_type,
                'balance' => number_format((float) $r->balance, 2, '.', ''),
            ])->values()->all(),
            'total_count' => (int) $base()->count(),
        ];
    }
}
