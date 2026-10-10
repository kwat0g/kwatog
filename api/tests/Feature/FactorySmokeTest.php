<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2.1 — Factory smoke test.
 *
 * Verify individual factories and their core foreign-key chains persist.
 */
class FactorySmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_department_factory_persists(): void
    {
        $dept = Department::factory()->create();
        $this->assertDatabaseHas('departments', ['id' => $dept->id]);
    }

    public function test_hr_position_factory_persists(): void
    {
        $pos = Position::factory()->create();
        $this->assertDatabaseHas('positions', ['id' => $pos->id]);
    }

    public function test_hr_employee_factory_persists(): void
    {
        $emp = Employee::factory()->create();
        $this->assertDatabaseHas('employees', ['id' => $emp->id]);
    }

    public function test_inventory_item_category_factory_persists(): void
    {
        $cat = ItemCategory::factory()->create();
        $this->assertDatabaseHas('item_categories', ['id' => $cat->id]);
    }

    public function test_inventory_item_factory_persists(): void
    {
        $item = Item::factory()->create();
        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_inventory_warehouse_factory_persists(): void
    {
        $wh = Warehouse::factory()->create();
        $this->assertDatabaseHas('warehouses', ['id' => $wh->id]);
    }

    public function test_inventory_warehouse_zone_factory_persists(): void
    {
        $zone = WarehouseZone::factory()->create();
        $this->assertDatabaseHas('warehouse_zones', ['id' => $zone->id]);
    }

    public function test_inventory_warehouse_location_factory_persists(): void
    {
        $loc = WarehouseLocation::factory()->create();
        $this->assertDatabaseHas('warehouse_locations', ['id' => $loc->id]);
    }

    public function test_inventory_stock_level_factory_persists(): void
    {
        $sl = StockLevel::factory()->create();
        $this->assertDatabaseHas('stock_levels', ['id' => $sl->id]);
    }

    public function test_accounting_customer_factory_persists(): void
    {
        $customer = Customer::factory()->create();
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_accounting_vendor_factory_persists(): void
    {
        $vendor = Vendor::factory()->create();
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);
    }

    public function test_crm_sales_order_factory_persists(): void
    {
        $so = SalesOrder::factory()->create();
        $this->assertDatabaseHas('sales_orders', ['id' => $so->id]);
    }

    public function test_purchasing_purchase_order_factory_persists(): void
    {
        $po = PurchaseOrder::factory()->create();
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id]);
    }

    public function test_inventory_grn_factory_persists(): void
    {
        $grn = GoodsReceiptNote::factory()->create();
        $this->assertDatabaseHas('goods_receipt_notes', ['id' => $grn->id]);
    }

    public function test_accounting_invoice_factory_persists(): void
    {
        $invoice = Invoice::factory()->create();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_payroll_period_factory_persists(): void
    {
        $period = PayrollPeriod::factory()->create();
        $this->assertDatabaseHas('payroll_periods', ['id' => $period->id]);
    }

    public function test_all_core_factories_persist(): void
    {
        $employee = Employee::factory()->create();
        $stockLevel = StockLevel::factory()->create();
        $salesOrder = SalesOrder::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->create();
        $payrollPeriod = PayrollPeriod::factory()->create();
        $grn = GoodsReceiptNote::factory()->create();
        $invoice = Invoice::factory()->create();

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('stock_levels', ['id' => $stockLevel->id]);
        $this->assertDatabaseHas('sales_orders', ['id' => $salesOrder->id]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $purchaseOrder->id]);
        $this->assertDatabaseHas('payroll_periods', ['id' => $payrollPeriod->id]);
        $this->assertDatabaseHas('goods_receipt_notes', ['id' => $grn->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }
}
