<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Payroll\Enums\BankFileGenerationStatus;
use App\Modules\Payroll\Enums\PayrollGlHandoffStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LifecycleStatusConstraintTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, list<string>>> */
    private const STATUSES = [
        'journal_entries' => ['status' => ['draft', 'posted', 'reversed']],
        'stock_adjustments' => ['status' => ['pending', 'approved']],
        'stock_count_sessions' => ['status' => ['draft', 'in_progress', 'completed', 'cancelled']],
        'stock_count_items' => ['status' => ['pending', 'counted', 'verified', 'adjusted']],
        'inspections' => ['status' => ['draft', 'in_progress', 'passed', 'failed', 'cancelled']],
        'deliveries' => ['status' => ['scheduled', 'loading', 'in_transit', 'delivered', 'confirmed', 'cancelled']],
        'payroll_periods' => [
            'status' => ['draft', 'processing', 'computed', 'approved', 'finalized', 'disbursed', 'voided'],
            'bank_file_status' => ['not_started', 'pending', 'manual_required', 'generated'],
            'gl_handoff_status' => ['not_started', 'pending', 'manual_required', 'posted', 'not_required'],
        ],
    ];

    /** @var array<string, class-string<\BackedEnum>> */
    private const ENUMS = [
        'journal_entries.status' => JournalEntryStatus::class,
        'stock_adjustments.status' => StockAdjustmentStatus::class,
        'stock_count_sessions.status' => StockCountSessionStatus::class,
        'stock_count_items.status' => StockCountItemStatus::class,
        'inspections.status' => InspectionStatus::class,
        'deliveries.status' => DeliveryStatus::class,
        'payroll_periods.status' => PayrollPeriodStatus::class,
        'payroll_periods.bank_file_status' => BankFileGenerationStatus::class,
        'payroll_periods.gl_handoff_status' => PayrollGlHandoffStatus::class,
    ];

    public function test_bounded_status_values_match_application_enums(): void
    {
        foreach (self::STATUSES as $table => $columns) {
            foreach ($columns as $column => $statuses) {
                $enum = self::ENUMS["{$table}.{$column}"];
                $enumValues = array_map(
                    static fn (\BackedEnum $case): string => (string) $case->value,
                    $enum::cases(),
                );

                sort($statuses);
                sort($enumValues);
                $this->assertSame($enumValues, $statuses, "Constraint values drifted from {$enum}.");
            }
        }
    }

    public function test_all_bounded_status_roots_have_a_database_guard(): void
    {
        $driver = DB::getDriverName();
        $this->assertContains($driver, ['pgsql', 'sqlite']);

        foreach (self::STATUSES as $table => $columns) {
            foreach ($columns as $column => $statuses) {
                $name = $table.'_'.$column.'_check';
                if ($table === 'payroll_periods' && $column !== 'status') {
                    $name = 'payroll_periods_'.$column.'_check';
                }
                if ($driver === 'pgsql') {
                    $row = DB::selectOne(
                        'SELECT pg_get_constraintdef(c.oid) AS definition
                           FROM pg_constraint c
                           JOIN pg_class r ON r.oid = c.conrelid
                          WHERE r.relname = ? AND c.conname = ?',
                        [$table, $name],
                    );
                    $this->assertNotNull($row, "Missing {$name}");
                    $definition = strtolower((string) $row->definition);
                    foreach ($statuses as $status) {
                        $this->assertStringContainsString($status, $definition);
                    }
                } else {
                    $guards = DB::table('sqlite_master')
                        ->where('type', 'trigger')
                        ->whereIn('name', [$name.'_insert_guard', $name.'_update_guard'])
                        ->pluck('name');
                    $this->assertCount(2, $guards, "Missing SQLite guards for {$table}.{$column}");
                }
            }
        }
    }

    public function test_invalid_journal_entry_status_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('journal_entries')->insert([
            'entry_number' => 'JE-INVALID-STATUS',
            'date' => '2026-08-13',
            'status' => 'not_a_status',
        ]);
    }

    public function test_invalid_stock_adjustment_status_is_rejected(): void
    {
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('stock_adjustments')->insert([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'direction' => 'in',
            'quantity' => 1,
            'status' => 'not_a_status',
        ]);
    }

    public function test_invalid_stock_count_session_status_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('stock_count_sessions')->insert([
            'session_number' => 'SC-INVALID-STATUS',
            'title' => 'Invalid status test',
            'status' => 'not_a_status',
            'created_by' => $user->id,
        ]);
    }

    public function test_invalid_stock_count_item_status_is_rejected(): void
    {
        $user = User::factory()->create();
        $session = StockCountSession::create([
            'session_number' => 'SC-VALID-STATUS',
            'title' => 'Invalid item status test',
            'created_by' => $user->id,
        ]);
        $location = WarehouseLocation::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('stock_count_items')->insert([
            'session_id' => $session->id,
            'location_id' => $location->id,
            'status' => 'not_a_status',
        ]);
    }

    public function test_invalid_inspection_status_is_rejected(): void
    {
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('inspections')->insert([
            'inspection_number' => 'INSP-INVALID-STATUS',
            'stage' => 'outgoing',
            'status' => 'not_a_status',
            'product_id' => $product->id,
            'batch_quantity' => 1,
            'sample_size' => 1,
        ]);
    }

    public function test_invalid_delivery_status_is_rejected(): void
    {
        $order = SalesOrder::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('deliveries')->insert([
            'delivery_number' => 'DEL-INVALID-STATUS',
            'sales_order_id' => $order->id,
            'scheduled_date' => '2026-08-13',
            'status' => 'not_a_status',
            'created_by' => $order->created_by,
        ]);
    }

    public function test_invalid_payroll_period_status_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('payroll_periods')->insert([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'payroll_date' => '2026-08-20',
            'created_by' => $user->id,
            'status' => 'not_a_status',
        ]);
    }

    public function test_invalid_payroll_bank_file_status_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('payroll_periods')->insert([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'payroll_date' => '2026-08-20',
            'created_by' => $user->id,
            'bank_file_status' => 'not_a_status',
        ]);
    }

    public function test_invalid_payroll_gl_handoff_status_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('payroll_periods')->insert([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'payroll_date' => '2026-08-20',
            'created_by' => $user->id,
            'gl_handoff_status' => 'not_a_status',
        ]);
    }
}
