<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPEN_LOAN_INDEX = 'employee_loans_one_open_type_unique';

    private const CLEARANCE_FOREIGN = 'loan_payments_clearance_id_foreign';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Loan lifecycle integrity migration requires PostgreSQL or SQLite.');
        }

        $duplicates = DB::table('employee_loans')
            ->select('employee_id', 'loan_type')
            ->whereIn('status', ['pending', 'active'])
            ->groupBy('employee_id', 'loan_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        if ($duplicates->isNotEmpty()) {
            $details = $duplicates
                ->map(static fn (object $row): string => "employee {$row->employee_id} / {$row->loan_type}")
                ->implode(', ');
            throw new RuntimeException('Cannot enforce one open loan per type; reconcile duplicate rows first: '.$details);
        }

        if (DB::table('loan_payments')->whereNotNull('clearance_id')->whereNotExists(
            static fn ($query) => $query->selectRaw('1')
                ->from('clearances')
                ->whereColumn('clearances.id', 'loan_payments.clearance_id'),
        )->exists()) {
            throw new RuntimeException('Cannot enforce loan payment clearance provenance while orphan clearance references exist.');
        }

        $predicate = "status IN ('pending', 'active')";
        DB::statement(
            'CREATE UNIQUE INDEX '.self::OPEN_LOAN_INDEX
            .' ON employee_loans (employee_id, loan_type) WHERE '.$predicate,
        );

        $hasForeign = collect(Schema::getForeignKeys('loan_payments'))
            ->contains(static fn (array $foreign): bool => $foreign['name'] === self::CLEARANCE_FOREIGN);
        if (! $hasForeign) {
            Schema::table('loan_payments', function (Blueprint $table): void {
                $table->foreign('clearance_id', self::CLEARANCE_FOREIGN)
                    ->references('id')
                    ->on('clearances')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::OPEN_LOAN_INDEX);

        $hasForeign = collect(Schema::getForeignKeys('loan_payments'))
            ->contains(static fn (array $foreign): bool => $foreign['name'] === self::CLEARANCE_FOREIGN);
        if ($hasForeign) {
            Schema::table('loan_payments', function (Blueprint $table): void {
                $table->dropForeign(self::CLEARANCE_FOREIGN);
            });
        }
    }
};
