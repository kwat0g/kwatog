<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('return_case_id')->nullable()->after('customer_id')->constrained('return_cases')->nullOnDelete();
            $table->unique('return_case_id', 'sales_orders_return_case_unique');
        });
        $this->invoiceStatuses(true);
    }

    public function down(): void
    {
        if (DB::table('deliveries')->where('invoice_handoff_status', 'not_required')->exists()) {
            throw new RuntimeException('No-charge deliveries exist; preserve their billing exemption before rolling back.');
        }
        $this->invoiceStatuses(false);
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropUnique('sales_orders_return_case_unique');
            $table->dropConstrainedForeignId('return_case_id');
        });
    }

    private function invoiceStatuses(bool $allowNoCharge): void
    {
        $name = 'deliveries_invoice_handoff_status_lifecycle_check';
        $values = "'not_started', 'generated', 'manual_required'".($allowNoCharge ? ", 'not_required'" : '');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deliveries DROP CONSTRAINT IF EXISTS '.$name);
            DB::statement('ALTER TABLE deliveries ADD CONSTRAINT '.$name.' CHECK (invoice_handoff_status IN ('.$values.') OR invoice_handoff_status IS NULL)');
        } else {
            foreach (['insert', 'update'] as $operation) {
                DB::statement('DROP TRIGGER IF EXISTS '.$name.'_'.$operation.'_guard');
                DB::statement('CREATE TRIGGER '.$name.'_'.$operation.'_guard BEFORE '.strtoupper($operation).' ON deliveries WHEN NEW.invoice_handoff_status IS NOT NULL AND NEW.invoice_handoff_status NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid deliveries.invoice_handoff_status\'); END');
            }
        }
    }
};
