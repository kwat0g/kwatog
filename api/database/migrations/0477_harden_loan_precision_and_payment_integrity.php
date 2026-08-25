<?php

declare(strict_types=1);

use App\Modules\Loans\Enums\LoanPaymentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AMOUNT_CHECK = 'loan_payments_amount_positive';
    private const TYPE_CHECK = 'loan_payments_type_check';
    private const PAYROLL_FOREIGN = 'loan_payments_payroll_id_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('employee_loans') || ! Schema::hasTable('loan_payments')) {
            return;
        }

        $this->preflightPaymentRows();
        $this->widenInterestRate();

        if (Schema::hasTable('payrolls') && Schema::hasColumn('loan_payments', 'payroll_id')) {
            $existing = collect(Schema::getForeignKeys('loan_payments'))
                ->first(fn (array $foreignKey): bool => in_array(
                    'payroll_id',
                    (array) ($foreignKey['columns'] ?? []),
                    true,
                ));

            if ($existing === null) {
                Schema::table('loan_payments', function (Blueprint $table): void {
                    $table->foreign('payroll_id', self::PAYROLL_FOREIGN)
                        ->references('id')
                        ->on('payrolls')
                        ->nullOnDelete();
                });
            }
        }

        $this->addPaymentChecks();
    }

    public function down(): void
    {
        if (Schema::hasTable('loan_payments')) {
            $this->dropPaymentChecks();

            $existing = collect(Schema::getForeignKeys('loan_payments'))
                ->first(fn (array $foreignKey): bool => in_array(
                    'payroll_id',
                    (array) ($foreignKey['columns'] ?? []),
                    true,
                ));
            if ($existing !== null) {
                Schema::table('loan_payments', function (Blueprint $table): void {
                    $table->dropForeign([self::PAYROLL_FOREIGN]);
                });
            }
        }

        // This is a reversible schema widening; a rollback may truncate
        // precision, so the deployment process should not roll back after
        // rates with more than two decimal places have been introduced.
        if (Schema::hasTable('employee_loans') && Schema::hasColumn('employee_loans', 'interest_rate')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE employee_loans ALTER COLUMN interest_rate TYPE NUMERIC(5,2) USING interest_rate::numeric');
            } elseif ($driver === 'mysql') {
                DB::statement('ALTER TABLE employee_loans MODIFY interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00');
            }
        }
    }

    private function preflightPaymentRows(): void
    {
        if (DB::table('loan_payments')->where('amount', '<=', 0)->exists()) {
            throw new RuntimeException(
                'Cannot enforce positive loan payments until non-positive loan_payments.amount rows are reconciled.',
            );
        }

        $allowedTypes = array_map(
            static fn (LoanPaymentType $type): string => $type->value,
            LoanPaymentType::cases(),
        );
        if (DB::table('loan_payments')->whereNotIn('payment_type', $allowedTypes)->exists()) {
            throw new RuntimeException(
                'Cannot enforce loan payment types until unsupported loan_payments.payment_type rows are reconciled.',
            );
        }

        if (Schema::hasTable('payrolls')) {
            $hasOrphans = DB::table('loan_payments')
                ->whereNotNull('payroll_id')
                ->whereNotExists(static function ($query): void {
                    $query->selectRaw('1')
                        ->from('payrolls')
                        ->whereColumn('payrolls.id', 'loan_payments.payroll_id');
                })
                ->exists();
            if ($hasOrphans) {
                throw new RuntimeException(
                    'Cannot add the loan payment payroll foreign key until orphan payroll_id values are reconciled.',
                );
            }
        }
    }

    private function widenInterestRate(): void
    {
        if (! Schema::hasColumn('employee_loans', 'interest_rate')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_loans ALTER COLUMN interest_rate TYPE NUMERIC(8,6) USING interest_rate::numeric',
            );
        } elseif ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE employee_loans MODIFY interest_rate DECIMAL(8,6) NOT NULL DEFAULT 0.00',
            );
        } else {
            Schema::table('employee_loans', function (Blueprint $table): void {
                $table->decimal('interest_rate', 8, 6)->default(0.00)->change();
            });
        }
    }

    private function addPaymentChecks(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            if (! $this->postgresConstraintExists(self::AMOUNT_CHECK)) {
                DB::statement(
                    'ALTER TABLE loan_payments ADD CONSTRAINT '.self::AMOUNT_CHECK.' CHECK (amount > 0)',
                );
            }
            if (! $this->postgresConstraintExists(self::TYPE_CHECK)) {
                $types = implode(', ', array_map(
                    static fn (LoanPaymentType $type): string => "'".$type->value."'",
                    LoanPaymentType::cases(),
                ));
                DB::statement(
                    'ALTER TABLE loan_payments ADD CONSTRAINT '.self::TYPE_CHECK.' CHECK (payment_type IN ('.$types.'))',
                );
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        DB::statement(
            'CREATE TRIGGER IF NOT EXISTS '.self::AMOUNT_CHECK.'_insert_guard '
            .'BEFORE INSERT ON loan_payments WHEN NEW.amount <= 0 '
            ."BEGIN SELECT RAISE(ABORT, 'loan payment amount must be positive'); END",
        );
        DB::statement(
            'CREATE TRIGGER IF NOT EXISTS '.self::AMOUNT_CHECK.'_update_guard '
            .'BEFORE UPDATE OF amount ON loan_payments WHEN NEW.amount <= 0 '
            ."BEGIN SELECT RAISE(ABORT, 'loan payment amount must be positive'); END",
        );

        $types = implode(', ', array_map(
            static fn (LoanPaymentType $type): string => "'".$type->value."'",
            LoanPaymentType::cases(),
        ));
        DB::statement(
            'CREATE TRIGGER IF NOT EXISTS '.self::TYPE_CHECK.'_insert_guard '
            .'BEFORE INSERT ON loan_payments WHEN NEW.payment_type NOT IN ('.$types.') '
            ."BEGIN SELECT RAISE(ABORT, 'loan payment type is invalid'); END",
        );
        DB::statement(
            'CREATE TRIGGER IF NOT EXISTS '.self::TYPE_CHECK.'_update_guard '
            .'BEFORE UPDATE OF payment_type ON loan_payments WHEN NEW.payment_type NOT IN ('.$types.') '
            ."BEGIN SELECT RAISE(ABORT, 'loan payment type is invalid'); END",
        );
    }

    private function dropPaymentChecks(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE loan_payments DROP CONSTRAINT IF EXISTS '.self::AMOUNT_CHECK);
            DB::statement('ALTER TABLE loan_payments DROP CONSTRAINT IF EXISTS '.self::TYPE_CHECK);
        } elseif ($driver === 'sqlite') {
            foreach ([
                self::AMOUNT_CHECK.'_insert_guard',
                self::AMOUNT_CHECK.'_update_guard',
                self::TYPE_CHECK.'_insert_guard',
                self::TYPE_CHECK.'_update_guard',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }

    private function postgresConstraintExists(string $name): bool
    {
        return DB::table('pg_constraint')
            ->where('conname', $name)
            ->exists();
    }
};
