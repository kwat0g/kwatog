<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED = ['pending', 'active', 'paid', 'cancelled', 'rejected', 'write_off_pending', 'written_off'];

    public function up(): void
    {
        $this->replaceGuard(self::ALLOWED);
    }

    public function down(): void
    {
        $this->replaceGuard(['pending', 'active', 'paid', 'cancelled', 'rejected']);
    }

    /** @param list<string> $allowed */
    private function replaceGuard(array $allowed): void
    {
        if (! Schema::hasTable('employee_loans') || ! Schema::hasColumn('employee_loans', 'status')) {
            return;
        }

        $driver = DB::getDriverName();
        $name = 'employee_loans_status_lifecycle_check';
        $values = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $allowed,
        ));

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "employee_loans" DROP CONSTRAINT IF EXISTS "'.$name.'"');
            DB::statement('ALTER TABLE "employee_loans" ADD CONSTRAINT "'.$name.'" CHECK ("status" IN ('.$values.'))');

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_insert_guard"');
        DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_update_guard"');
        DB::statement(
            'CREATE TRIGGER "'.$name.'_insert_guard" BEFORE INSERT ON "employee_loans" '
            .'WHEN NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.') '
            .'BEGIN SELECT RAISE(ABORT, \'invalid employee_loans.status\'); END',
        );
        DB::statement(
            'CREATE TRIGGER "'.$name.'_update_guard" BEFORE UPDATE OF "status" ON "employee_loans" '
            .'WHEN NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.') '
            .'BEGIN SELECT RAISE(ABORT, \'invalid employee_loans.status\'); END',
        );
    }
};
