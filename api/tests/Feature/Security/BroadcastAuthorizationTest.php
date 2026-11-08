<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Models\Training;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\NcrTemplate;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BroadcastAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        $this->seed(RolePermissionSeeder::class);
        foreach (['accounting', 'crm', 'hr', 'inventory', 'maintenance', 'mrp', 'payroll', 'production', 'purchasing', 'supply_chain'] as $feature) {
            app(SettingsService::class)->set("modules.{$feature}", true, 'modules');
        }
        require base_path('routes/channels.php');
    }

    public function test_user_can_authorize_their_hash_id_private_channel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-user.{$user->hash_id}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_user_cannot_authorize_another_users_private_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-user.{$other->hash_id}",
            ])
            ->assertForbidden();
    }

    public function test_user_can_authorize_only_their_own_private_preferences_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->authorizeChannel($user, "private-user.preferences.{$user->hash_id}")
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel($user, "private-user.preferences.{$other->hash_id}")
            ->assertForbidden();
    }

    public function test_contact_inquiry_channel_matches_crm_reader_permission_and_feature(): void
    {
        $reader = $this->userWithPermissions('crm.inquiries.view');
        $unrelated = $this->userWithPermissions('crm.customers.manage');

        $this->authorizeChannel($reader, 'private-entity.contact_inquiry.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.contact_inquiry.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.crm', false, 'modules');
        $this->authorizeChannel($reader, 'private-entity.contact_inquiry.visible')->assertForbidden();
    }

    public function test_user_access_refresh_channel_is_self_only_without_notification_permission(): void
    {
        $user = $this->userWithPermissions();
        $other = $this->userWithPermissions();

        $this->assertFalse($user->hasPermission('notifications.view'));
        $this->authorizeChannel($user, "private-user.access.{$user->hash_id}")->assertOk();
        $this->authorizeChannel($user, "private-user.access.{$other->hash_id}")->assertForbidden();
    }

    public function test_approval_board_refresh_channel_requires_board_permission(): void
    {
        $viewer = $this->userWithPermissions('approvals.board.view');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($viewer, 'private-approvals.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-approvals.visible')->assertForbidden();
    }

    public function test_admin_sessions_refresh_channel_matches_the_sessions_api_permission(): void
    {
        $admin = $this->userWithPermissions('admin.settings.manage');
        $unrelated = $this->userWithPermissions('admin.users.manage');

        $this->authorizeChannel($admin, 'private-admin.sessions')->assertOk();
        $this->authorizeChannel($unrelated, 'private-admin.sessions')->assertForbidden();

        $admin->update(['is_active' => false]);
        $this->authorizeChannel($admin, 'private-admin.sessions')->assertUnauthorized();
    }

    public function test_customer_refresh_channels_match_customer_directory_permission(): void
    {
        $customer = Customer::factory()->create();
        $reader = $this->userWithPermissions('accounting.customers.view');
        $unrelated = $this->userWithPermissions('accounting.invoices.view');

        $this->authorizeChannel($reader, 'private-entity.customer.visible')->assertOk();
        $this->authorizeChannel($reader, "private-accounting.customer.{$customer->hash_id}")->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.customer.visible')->assertForbidden();
        $this->authorizeChannel($unrelated, "private-accounting.customer.{$customer->hash_id}")->assertForbidden();
    }

    public function test_customer_profile_channel_is_scoped_to_the_active_portal_account(): void
    {
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        $customer = Customer::factory()->create(['is_active' => true]);
        $otherCustomer = Customer::factory()->create(['is_active' => true]);
        $portalUser = CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Customer Profile Contact',
            'email' => 'customer-profile-'.uniqid().'@example.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.profile.{$customer->hash_id}",
        ])->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.profile.{$otherCustomer->hash_id}",
        ])->assertForbidden();
    }

    public function test_cache_group_refresh_channels_match_the_affected_api_permissions(): void
    {
        $dashboardUser = $this->userWithPermissions();
        $financeViewer = $this->userWithPermissions('accounting.statements.view');
        $purchasingViewer = $this->userWithPermissions('purchasing.view');
        $unrelated = $this->userWithPermissions();

        // The default dashboard-layout API is available to every active user;
        // this channel carries only a refresh signal, never dashboard content.
        $this->authorizeChannel($dashboardUser, 'private-cache-group.dashboards')->assertOk();
        $dashboardUser->update(['is_active' => false]);
        $this->authorizeChannel($dashboardUser, 'private-cache-group.dashboards')->assertUnauthorized();

        $this->authorizeChannel($financeViewer, 'private-cache-group.financial-statements')->assertOk();
        $this->authorizeChannel($unrelated, 'private-cache-group.financial-statements')->assertForbidden();

        $this->authorizeChannel($purchasingViewer, 'private-cache-group.procurement-chain')->assertOk();
        $this->authorizeChannel($unrelated, 'private-cache-group.procurement-chain')->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-cache-group.unregistered')->assertForbidden();

        app(SettingsService::class)->set('modules.purchasing', false, 'modules');
        $this->authorizeChannel($purchasingViewer, 'private-cache-group.procurement-chain')->assertForbidden();
    }

    public function test_finance_dashboard_refresh_channel_requires_its_dashboard_permission(): void
    {
        $financeViewer = $this->userWithPermissions('dashboard.accounting.view');
        $unrelated = $this->userWithPermissions('inventory.view');

        $this->authorizeChannel($financeViewer, 'private-dashboard.finance')->assertOk();
        $this->authorizeChannel($unrelated, 'private-dashboard.finance')->assertForbidden();

        $financeViewer->update(['is_active' => false]);
        $this->authorizeChannel($financeViewer, 'private-dashboard.finance')->assertUnauthorized();
    }

    public function test_chain_bottleneck_refresh_channel_requires_the_widget_permission(): void
    {
        $viewer = $this->userWithPermissions('dashboard.view_bottlenecks');
        $broaderDashboardViewer = $this->userWithPermissions('dashboard.accounting.view');

        $this->authorizeChannel($viewer, 'private-dashboard.chain-bottlenecks')->assertOk();
        $this->authorizeChannel($broaderDashboardViewer, 'private-dashboard.chain-bottlenecks')->assertForbidden();

        $viewer->update(['is_active' => false]);
        $this->authorizeChannel($viewer, 'private-dashboard.chain-bottlenecks')->assertUnauthorized();
    }

    public function test_purchasing_dashboard_refresh_channel_requires_its_api_permission(): void
    {
        $purchasingViewer = $this->userWithPermissions('dashboard.purchasing.view');
        $unrelated = $this->userWithPermissions('purchasing.view');

        $this->authorizeChannel($purchasingViewer, 'private-dashboard.purchasing')->assertOk();
        $this->authorizeChannel($unrelated, 'private-dashboard.purchasing')->assertForbidden();

        $purchasingViewer->update(['is_active' => false]);
        $this->authorizeChannel($purchasingViewer, 'private-dashboard.purchasing')->assertUnauthorized();
    }

    public function test_demand_forecast_channels_match_forecasting_read_scope(): void
    {
        app(SettingsService::class)->set('modules.forecasting', true, 'modules');
        $viewer = $this->userWithPermissions('forecasting.view');
        $unrelated = $this->userWithPermissions('mrp.plans.view');
        $forecast = DemandForecast::factory()->create();

        $this->authorizeChannel($viewer, 'private-entity.demand_forecast.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.demand_forecast.visible')->assertForbidden();
        $this->authorizeChannel($viewer, "private-forecast.demand-forecast.{$forecast->hash_id}")->assertOk();
        $this->authorizeChannel($unrelated, "private-forecast.demand-forecast.{$forecast->hash_id}")->assertForbidden();

        $viewer->update(['is_active' => false]);
        $this->authorizeChannel($viewer, 'private-entity.demand_forecast.visible')->assertUnauthorized();
    }

    public function test_backup_refresh_channel_requires_the_backup_history_permission(): void
    {
        $viewer = $this->userWithPermissions('admin.backups.view');
        $unrelated = $this->userWithPermissions('hr.employees.view');

        $this->authorizeChannel($viewer, 'private-admin.backups')->assertOk();
        $this->authorizeChannel($unrelated, 'private-admin.backups')->assertForbidden();

        $viewer->update(['is_active' => false]);
        $this->authorizeChannel($viewer, 'private-admin.backups')->assertUnauthorized();
    }

    public function test_chain_listener_refresh_channel_matches_recovery_list_permission(): void
    {
        $viewer = $this->userWithPermissions('dashboard.chain_recovery.view');
        $unrelated = $this->userWithPermissions('hr.employees.view');

        $this->authorizeChannel($viewer, 'private-chain.listener-runs')->assertOk();
        $this->authorizeChannel($unrelated, 'private-chain.listener-runs')->assertForbidden();

        $viewer->update(['is_active' => false]);
        $this->authorizeChannel($viewer, 'private-chain.listener-runs')->assertUnauthorized();
    }

    public function test_government_table_refresh_channel_matches_table_list_permission(): void
    {
        $manager = $this->userWithPermissions('admin.gov_tables.manage');
        $importerOnly = $this->userWithPermissions('payroll.gov_tables.manage');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($manager, 'private-entity.government_table.visible')->assertOk();
        $this->authorizeChannel($importerOnly, 'private-entity.government_table.visible')->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.government_table.visible')->assertForbidden();
    }

    public function test_department_refresh_channel_matches_hr_directory_read_permissions(): void
    {
        $hrReader = $this->userWithPermissions('hr.departments.view');
        $directoryReader = $this->userWithPermissions('hr.directory.view');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($hrReader, 'private-entity.department.visible')->assertOk();
        $this->authorizeChannel($directoryReader, 'private-entity.department.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.department.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        $this->authorizeChannel($hrReader, 'private-entity.department.visible')->assertForbidden();
    }

    public function test_position_refresh_channel_matches_position_list_permission_and_feature(): void
    {
        $reader = $this->userWithPermissions('hr.positions.view');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($reader, 'private-entity.position.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.position.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        $this->authorizeChannel($reader, 'private-entity.position.visible')->assertForbidden();
    }

    public function test_position_record_signal_is_limited_to_position_readers_or_employee_scopes_linked_to_it(): void
    {
        $department = Department::factory()->create();
        $position = Position::factory()->create(['department_id' => $department->id]);
        Employee::factory()->create([
            'department_id' => $department->id,
            'position_id' => $position->id,
        ]);
        $otherDepartment = Department::factory()->create();
        $otherPosition = Position::factory()->create(['department_id' => $otherDepartment->id]);

        $positionReader = $this->userWithPermissions('hr.positions.view');
        $employeeReader = $this->userWithPermissions('hr.employees.view');
        $employeeReader->update(['employee_id' => Employee::factory()->create(['department_id' => $department->id])->id]);
        $otherDepartmentReader = $this->userWithPermissions('hr.employees.view');
        $otherDepartmentReader->update(['employee_id' => Employee::factory()->create(['department_id' => $otherDepartment->id])->id]);
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($positionReader, "private-position.{$position->hash_id}")->assertOk();
        $this->authorizeChannel($employeeReader, "private-position.{$position->hash_id}")->assertOk();
        $this->authorizeChannel($otherDepartmentReader, "private-position.{$position->hash_id}")->assertForbidden();
        $this->authorizeChannel($employeeReader, "private-position.{$otherPosition->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, "private-position.{$position->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        $this->authorizeChannel($positionReader, "private-position.{$position->hash_id}")->assertForbidden();
    }

    public function test_employee_channels_match_company_department_record_and_personal_scope(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $otherDepartment = Department::factory()->create();
        $otherEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);

        $departmentReader = $this->userWithPermissions('hr.employees.view');
        $departmentReader->update(['employee_id' => Employee::factory()->create(['department_id' => $department->id])->id]);
        $otherDepartmentReader = $this->userWithPermissions('hr.employees.view');
        $otherDepartmentReader->update(['employee_id' => Employee::factory()->create(['department_id' => $otherDepartment->id])->id]);
        $companyReader = $this->userWithPermissions('hr.employees.view', 'hr.employees.view_sensitive');
        $unrelated = $this->userWithPermissions();
        $linkedUser = User::factory()->create(['employee_id' => $employee->id]);
        $otherUser = User::factory()->create(['employee_id' => $otherEmployee->id]);

        $this->authorizeChannel($companyReader, 'private-entity.employee.visible')->assertOk();
        $this->authorizeChannel($departmentReader, 'private-entity.employee.visible')->assertForbidden();
        $this->authorizeChannel($departmentReader, "private-entity.employee.department.{$department->hash_id}")->assertOk();
        $this->authorizeChannel($otherDepartmentReader, "private-entity.employee.department.{$department->hash_id}")->assertForbidden();
        $this->authorizeChannel($companyReader, "private-employee.{$employee->hash_id}")->assertOk();
        $this->authorizeChannel($departmentReader, "private-employee.{$employee->hash_id}")->assertOk();
        $this->authorizeChannel($otherDepartmentReader, "private-employee.{$employee->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, "private-employee.{$employee->hash_id}")->assertForbidden();
        $this->authorizeChannel($linkedUser, "private-employee.user.{$linkedUser->hash_id}")->assertOk();
        $this->authorizeChannel($linkedUser, "private-employee.user.{$otherUser->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        $this->authorizeChannel($companyReader, 'private-entity.employee.visible')->assertForbidden();
        $this->authorizeChannel($linkedUser, "private-employee.{$employee->hash_id}")->assertForbidden();
    }

    public function test_employee_training_channels_match_list_permission_and_record_scope(): void
    {
        $employee = Employee::factory()->create();
        $record = EmployeeTraining::query()->create([
            'employee_id' => $employee->id,
            'training_id' => Training::query()->create(['name' => 'Channel fixture'])->id,
            'scheduled_for' => '2026-10-01',
        ]);
        $owner = User::factory()->create(['employee_id' => $employee->id]);
        $sameDepartmentReader = $this->userWithPermissions('hr.employees.trainings.view');
        $sameDepartmentReader->update(['employee_id' => $employee->id]);
        $otherDepartmentReader = $this->userWithPermissions('hr.employees.trainings.view');
        $otherDepartmentReader->update(['employee_id' => Employee::factory()->create()->id]);
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($sameDepartmentReader, 'private-entity.employee_training.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.employee_training.visible')->assertForbidden();
        $this->authorizeChannel($owner, "private-employee_training.{$record->hash_id}")->assertOk();
        $this->authorizeChannel($sameDepartmentReader, "private-employee_training.{$record->hash_id}")->assertOk();
        $this->authorizeChannel($otherDepartmentReader, "private-employee_training.{$record->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, "private-employee_training.{$record->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        $this->authorizeChannel($owner, "private-employee_training.{$record->hash_id}")->assertForbidden();
    }

    public function test_salary_adjustment_channels_match_the_maker_checker_read_permission(): void
    {
        $employee = Employee::factory()->create();
        $requester = User::factory()->create();
        $adjustment = SalaryAdjustment::query()->create([
            'employee_id' => $employee->id,
            'from_basic_monthly_salary' => '30000.00',
            'to_basic_monthly_salary' => '32000.00',
            'effective_date' => '2026-11-01',
            'requested_by' => $requester->id,
        ]);
        $viewer = $this->userWithPermissions('hr.salary_adjustments.view');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($viewer, 'private-entity.salary_adjustment.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.salary_adjustment.visible')->assertForbidden();
        $this->authorizeChannel($viewer, "private-hr.salary-adjustment.{$adjustment->hash_id}")->assertOk();
        $this->authorizeChannel($unrelated, "private-hr.salary-adjustment.{$adjustment->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        $this->authorizeChannel($viewer, "private-hr.salary-adjustment.{$adjustment->hash_id}")->assertForbidden();
    }

    public function test_training_catalog_channels_require_catalog_view_permission(): void
    {
        $training = Training::query()->create(['name' => 'Channel fixture']);
        $viewer = $this->userWithPermissions('hr.trainings.view');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($viewer, 'private-entity.training.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.training.visible')->assertForbidden();
        $this->authorizeChannel($viewer, "private-training.{$training->hash_id}")->assertOk();
        $this->authorizeChannel($unrelated, "private-training.{$training->hash_id}")->assertForbidden();
    }

    public function test_de_minimis_refresh_channel_matches_its_payroll_list_permission_and_feature(): void
    {
        $viewer = $this->userWithPermissions('payroll.adjustments.create');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($viewer, 'private-entity.payroll_de_minimis.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.payroll_de_minimis.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.payroll', false, 'modules');
        $this->authorizeChannel($viewer, 'private-entity.payroll_de_minimis.visible')->assertForbidden();
    }

    public function test_payroll_anomaly_refresh_channel_matches_anomaly_review_permission_and_feature(): void
    {
        $reviewer = $this->userWithPermissions('payroll.anomalies.review');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($reviewer, 'private-entity.payroll_anomaly.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.payroll_anomaly.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.payroll', false, 'modules');
        $this->authorizeChannel($reviewer, 'private-entity.payroll_anomaly.visible')->assertForbidden();
    }

    public function test_driver_can_authorize_only_their_own_personal_refresh_channel(): void
    {
        $driverRoleId = Role::query()->where('slug', 'driver')->value('id');
        $driver = User::factory()->create(['role_id' => $driverRoleId]);
        $otherDriver = User::factory()->create(['role_id' => $driverRoleId]);

        $this->authorizeChannel($driver, "private-user.{$driver->hash_id}")->assertOk();
        $this->authorizeChannel($driver, "private-user.{$otherDriver->hash_id}")->assertForbidden();
        $this->authorizeChannel($driver, "private-driver.{$driver->hash_id}")->assertOk();
        $this->authorizeChannel($driver, "private-driver.{$otherDriver->hash_id}")->assertForbidden();
    }

    public function test_driver_refresh_channel_uses_driver_api_permission_not_notification_permission(): void
    {
        $driver = $this->userWithPermissions('supply_chain.driver.access');
        $otherDriver = $this->userWithPermissions('supply_chain.driver.access');

        $this->assertFalse($driver->hasPermission('notifications.view'));
        $this->authorizeChannel($driver, "private-user.{$driver->hash_id}")->assertForbidden();
        $this->authorizeChannel($driver, "private-driver.{$driver->hash_id}")->assertOk();
        $this->authorizeChannel($driver, "private-driver.{$otherDriver->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.supply_chain', false, 'modules');
        $this->authorizeChannel($driver, "private-driver.{$driver->hash_id}")->assertForbidden();
    }

    public function test_maintenance_work_order_channels_match_viewer_and_assigned_employee_scope(): void
    {
        $viewer = $this->userWithPermissions('maintenance.view');
        $assigned = $this->userWithPermissions('maintenance.work.assigned');
        $employee = Employee::factory()->create();
        $assigned->update(['employee_id' => $employee->id]);
        $unrelated = $this->userWithPermissions('maintenance.work.assigned');
        $unrelatedEmployee = Employee::factory()->create();
        $unrelated->update(['employee_id' => $unrelatedEmployee->id]);
        $machine = Machine::factory()->create(['status' => 'idle']);
        $order = MaintenanceWorkOrder::query()->create([
            'mwo_number' => 'MWO-CHANNEL-'.uniqid(),
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'type' => 'corrective',
            'priority' => 'high',
            'description' => 'Private channel authorization fixture',
            'status' => 'assigned',
            'assigned_to' => $employee->id,
            'created_by' => $viewer->id,
        ]);

        $this->authorizeChannel($viewer, "private-maintenance_work_order.{$order->hash_id}")->assertOk();
        $this->authorizeChannel($assigned, "private-maintenance_work_order.{$order->hash_id}")->assertOk();
        $this->authorizeChannel($unrelated, "private-maintenance_work_order.{$order->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.maintenance_work_order.visible')->assertOk();
        $this->authorizeChannel($assigned, 'private-entity.maintenance_work_order.visible')->assertForbidden();
        $this->authorizeChannel($assigned, "private-maintenance.user.{$assigned->hash_id}")->assertOk();
        $this->authorizeChannel($assigned, "private-maintenance.user.{$unrelated->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.maintenance', false, 'modules');
        $this->authorizeChannel($viewer, "private-maintenance_work_order.{$order->hash_id}")->assertForbidden();
    }

    public function test_maintenance_schedule_channels_match_schedule_api_permission_and_feature(): void
    {
        $viewer = $this->userWithPermissions('maintenance.view');
        $unauthorized = $this->userWithPermissions();
        $machine = Machine::factory()->create();
        $schedule = MaintenanceSchedule::query()->create([
            'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id,
            'schedule_type' => 'preventive',
            'description' => 'Schedule channel scope regression',
            'interval_type' => 'days',
            'interval_value' => 30,
            'estimated_duration_minutes' => 60,
            'is_active' => true,
        ]);

        $this->authorizeChannel($viewer, "private-maintenance_schedule.{$schedule->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.maintenance_schedule.visible')->assertOk();
        $this->authorizeChannel($unauthorized, "private-maintenance_schedule.{$schedule->hash_id}")->assertForbidden();
        $this->authorizeChannel($unauthorized, 'private-entity.maintenance_schedule.visible')->assertForbidden();
        $this->authorizeChannel($viewer, 'private-maintenance_schedule.unknown')->assertForbidden();

        app(SettingsService::class)->set('modules.maintenance', false, 'modules');
        $this->authorizeChannel($viewer, "private-maintenance_schedule.{$schedule->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.maintenance_schedule.visible')->assertForbidden();
    }

    public function test_clearance_record_and_user_channels_match_the_separation_api_scope(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $departmentUser = $this->userWithPermissions('hr.separation.view');
        $departmentUserEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $departmentUser->update(['employee_id' => $departmentUserEmployee->id]);

        $otherDepartment = Department::factory()->create();
        $outsider = $this->userWithPermissions('hr.separation.view');
        $outsiderEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);
        $outsider->update(['employee_id' => $outsiderEmployee->id]);
        $globalViewer = $this->userWithPermissions('hr.separation.view', 'hr.separation.view_all');

        $clearance = Clearance::query()->create([
            'clearance_no' => 'CLR-CHANNEL-'.uniqid(),
            'employee_id' => $employee->id,
            'separation_date' => now()->toDateString(),
            'separation_reason' => 'resigned',
            'clearance_items' => [],
            'status' => 'pending',
            'initiated_by' => $globalViewer->id,
        ]);

        $this->authorizeChannel($departmentUser, "private-clearance.{$clearance->hash_id}")->assertOk();
        $this->authorizeChannel($globalViewer, "private-clearance.{$clearance->hash_id}")->assertOk();
        $this->authorizeChannel($outsider, "private-clearance.{$clearance->hash_id}")->assertForbidden();
        $this->authorizeChannel($departmentUser, "private-hr.clearance.user.{$departmentUser->hash_id}")->assertOk();
        $this->authorizeChannel($departmentUser, "private-hr.clearance.user.{$outsider->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.hr', false, 'modules');
        $this->authorizeChannel($globalViewer, "private-clearance.{$clearance->hash_id}")->assertForbidden();
    }

    public function test_rfq_record_channel_uses_the_same_scope_as_the_rfq_api(): void
    {
        $creator = User::factory()->create();
        $requester = User::factory()->create();
        $outsider = User::factory()->create();
        $pr = PurchaseRequest::factory()->create(['requested_by' => $requester->id]);
        $rfq = RequestForQuote::factory()->create([
            'purchase_request_id' => $pr->id,
            'created_by' => $creator->id,
        ]);

        $this->authorizeChannel($creator, "private-rfq.{$rfq->hash_id}")->assertOk();
        $this->authorizeChannel($requester, "private-rfq.{$rfq->hash_id}")->assertOk();
        $this->authorizeChannel($outsider, "private-rfq.{$rfq->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.purchasing', false, 'modules');
        $this->authorizeChannel($creator, "private-rfq.{$rfq->hash_id}")->assertForbidden();
    }

    public function test_rfq_list_channel_requires_the_permission_that_opens_the_list_endpoint(): void
    {
        $viewer = $this->userWithPermissions('purchasing.rfq.view');
        $requesterOnly = User::factory()->create();

        $this->authorizeChannel($viewer, 'private-entity.request_for_quote.visible')->assertOk();
        $this->authorizeChannel($requesterOnly, 'private-entity.request_for_quote.visible')->assertForbidden();
    }

    public function test_inspection_detail_and_list_channels_require_quality_api_permission_and_feature(): void
    {
        app(SettingsService::class)->set('modules.quality', true, 'modules');
        $viewer = $this->userWithPermissions('quality.inspections.view');
        $unrelated = $this->userWithPermissions('inventory.view');
        $inspection = Inspection::create([
            'inspection_number' => 'QC-CHANNEL-'.substr(uniqid(), -6),
            'stage' => 'outgoing',
            'status' => 'draft',
            'product_id' => Product::factory()->create()->id,
            'batch_quantity' => 10,
            'sample_size' => 5,
        ]);

        $this->authorizeChannel($viewer, "private-inspection.{$inspection->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.inspection.visible')->assertOk();
        $this->authorizeChannel($unrelated, "private-inspection.{$inspection->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.inspection.visible')->assertForbidden();

        $missingHash = app('hashids')->encode(999999999);
        $this->authorizeChannel($viewer, "private-inspection.{$missingHash}")->assertForbidden();
        app(SettingsService::class)->set('modules.quality', false, 'modules');
        $this->authorizeChannel($viewer, "private-inspection.{$inspection->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.inspection.visible')->assertForbidden();
    }

    public function test_calibration_and_ppap_channels_require_their_api_permissions_and_live_records(): void
    {
        app(SettingsService::class)->set('modules.quality', true, 'modules');
        $calibration = CalibrationRecord::create([
            'equipment_code' => 'CAL-AUTH-001',
            'name' => 'Authorization gauge',
            'frequency_days' => 365,
            'status' => 'active',
        ]);
        $ppap = PpapSubmission::create([
            'ppap_number' => 'PP-AUTH-001',
            'vendor_id' => Vendor::factory()->create()->id,
            'item_id' => Item::factory()->create()->id,
            'ppap_level' => '3',
            'submission_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $calibrationViewer = $this->userWithPermissions('quality.calibration.view');
        $ppapViewer = $this->userWithPermissions('quality.ppap.view');
        $unrelated = $this->userWithPermissions('quality.inspections.view');

        $this->authorizeChannel($calibrationViewer, "private-calibration.{$calibration->hash_id}")->assertOk();
        $this->authorizeChannel($calibrationViewer, 'private-entity.calibration.visible')->assertOk();
        $this->authorizeChannel($unrelated, "private-calibration.{$calibration->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.calibration.visible')->assertForbidden();
        $this->authorizeChannel($ppapViewer, "private-ppap.{$ppap->hash_id}")->assertOk();
        $this->authorizeChannel($ppapViewer, 'private-entity.ppap.visible')->assertOk();
        $this->authorizeChannel($unrelated, "private-ppap.{$ppap->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.ppap.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.quality', false, 'modules');
        $this->authorizeChannel($calibrationViewer, "private-calibration.{$calibration->hash_id}")->assertForbidden();
        $this->authorizeChannel($ppapViewer, "private-ppap.{$ppap->hash_id}")->assertForbidden();
    }

    public function test_inspection_spec_channel_matches_with_trashed_detail_route_permissions(): void
    {
        app(SettingsService::class)->set('modules.quality', true, 'modules');
        $viewer = $this->userWithPermissions('quality.specs.view');
        $unrelated = $this->userWithPermissions('quality.inspections.view');
        $product = Product::factory()->create();
        $spec = InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $viewer->id,
        ]);
        $spec->delete();

        $this->authorizeChannel($viewer, "private-inspection_spec.{$spec->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.inspection_spec.visible')->assertOk();
        $this->authorizeChannel($unrelated, "private-inspection_spec.{$spec->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.inspection_spec.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.quality', false, 'modules');
        $this->authorizeChannel($viewer, "private-inspection_spec.{$spec->hash_id}")->assertForbidden();
    }

    public function test_ncr_template_channels_require_quality_ncr_read_permission(): void
    {
        app(SettingsService::class)->set('modules.quality', true, 'modules');
        $template = NcrTemplate::create([
            'name' => 'Channel test template',
            'source' => 'incoming_inspection',
            'severity' => 'major',
            'created_by' => User::factory()->create()->id,
            'is_active' => true,
        ]);
        $viewer = $this->userWithPermissions('quality.ncr.view');
        $unrelated = $this->userWithPermissions('quality.inspections.view');

        $this->authorizeChannel($viewer, "private-ncr_template.{$template->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.ncr_template.visible')->assertOk();
        $this->authorizeChannel($unrelated, "private-ncr_template.{$template->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.ncr_template.visible')->assertForbidden();
    }

    public function test_shipment_lot_channels_require_quality_inspection_read_permission(): void
    {
        app(SettingsService::class)->set('modules.quality', true, 'modules');
        $customer = Customer::factory()->create();
        $salesOrder = SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $delivery = Delivery::create([
            'delivery_number' => 'DL-LOT-AUTH-001',
            'sales_order_id' => $salesOrder->id,
            'status' => 'delivered',
            'scheduled_date' => now()->toDateString(),
            'created_by' => User::factory()->create()->id,
        ]);
        $lot = ShipmentLot::create([
            'lot_number' => 'LOT-AUTH-0001',
            'delivery_id' => $delivery->id,
            'customer_id' => $customer->id,
            'work_order_ids' => [],
            'quantity' => 1,
            'lot_date' => now()->toDateString(),
        ]);
        $viewer = $this->userWithPermissions('quality.inspections.view');
        $unrelated = $this->userWithPermissions('quality.ncr.view');

        $this->authorizeChannel($viewer, "private-shipment_lot.{$lot->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.shipment_lot.visible')->assertOk();
        $this->authorizeChannel($unrelated, "private-shipment_lot.{$lot->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.shipment_lot.visible')->assertForbidden();
    }

    public function test_ncr_detail_and_list_channels_require_ncr_api_permission_and_feature(): void
    {
        app(SettingsService::class)->set('modules.quality', true, 'modules');
        $viewer = $this->userWithPermissions('quality.ncr.view');
        $unrelated = $this->userWithPermissions('quality.inspections.view');
        $ncr = NonConformanceReport::factory()->create();

        $this->authorizeChannel($viewer, "private-ncr.{$ncr->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.ncr.visible')->assertOk();
        $this->authorizeChannel($unrelated, "private-ncr.{$ncr->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.ncr.visible')->assertForbidden();

        $missingHash = app('hashids')->encode(999999999);
        $this->authorizeChannel($viewer, "private-ncr.{$missingHash}")->assertForbidden();
        app(SettingsService::class)->set('modules.quality', false, 'modules');
        $this->authorizeChannel($viewer, "private-ncr.{$ncr->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.ncr.visible')->assertForbidden();
    }

    public function test_supplier_catalog_list_channels_use_their_matching_api_permissions(): void
    {
        $listingViewer = $this->userWithPermissions('purchasing.supplier_listings.view');
        $approvedSupplierViewer = $this->userWithPermissions('purchasing.po.create');
        $unrelated = $this->userWithPermissions('inventory.view');

        $this->authorizeChannel($listingViewer, 'private-entity.supplier_listing.visible')->assertOk();
        $this->authorizeChannel($listingViewer, 'private-entity.approved_supplier.visible')->assertForbidden();
        $this->authorizeChannel($approvedSupplierViewer, 'private-entity.approved_supplier.visible')->assertOk();
        $this->authorizeChannel($approvedSupplierViewer, 'private-entity.supplier_listing.visible')->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.supplier_listing.visible')->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.approved_supplier.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.purchasing', false, 'modules');
        $this->authorizeChannel($listingViewer, 'private-entity.supplier_listing.visible')->assertForbidden();
        $this->authorizeChannel($approvedSupplierViewer, 'private-entity.approved_supplier.visible')->assertForbidden();
    }

    public function test_supplier_portal_can_only_authorize_its_own_vendor_refresh_channel(): void
    {
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        $vendor = Vendor::factory()->create(['is_active' => true]);
        $otherVendor = Vendor::factory()->create(['is_active' => true]);
        $supplier = SupplierPortalUser::factory()->create([
            'vendor_id' => $vendor->id,
            'must_change_password' => false,
        ]);

        $channel = "private-supplier.vendor.{$vendor->hash_id}";
        $otherVendorChannel = "private-supplier.vendor.{$otherVendor->hash_id}";

        $this->actingAs($supplier, 'supplier_portal')
            ->postJson('/api/v1/b2b/supplier/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $channel,
            ])
            ->assertOk();

        $this->actingAs($supplier, 'supplier_portal')
            ->postJson('/api/v1/b2b/supplier/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $otherVendorChannel,
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_authorize_a_private_channel(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-user.{$user->hash_id}",
        ])->assertUnauthorized();
    }

    public function test_user_without_notification_permission_cannot_authorize_their_channel(): void
    {
        $user = User::factory()->create();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => DB::table('permissions')->where('slug', 'notifications.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $user->id,
            'reason' => 'Private notification channel test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->flushPermissionsCache();

        $this->actingAs($user)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-user.{$user->hash_id}",
            ])
            ->assertForbidden();
    }

    public function test_department_head_can_only_subscribe_to_purchase_requests_in_their_row_scope(): void
    {
        $ownDepartment = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $head = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $ownDepartment->id])->id,
        ]);
        $requester = User::factory()->create();
        $own = PurchaseRequest::factory()->create([
            'department_id' => $ownDepartment->id,
            'requested_by' => $requester->id,
        ]);
        $foreign = PurchaseRequest::factory()->create([
            'department_id' => $otherDepartment->id,
            'requested_by' => $requester->id,
        ]);

        $this->authorizeChain($head, $own, 'purchase_request')->assertOk();
        $this->authorizeChain($head, $foreign, 'purchase_request')->assertForbidden();
    }

    public function test_department_head_cannot_subscribe_to_purchase_orders_outside_their_row_scope(): void
    {
        $ownDepartment = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $head = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $ownDepartment->id])->id,
        ]);
        $requester = User::factory()->create();
        $ownRequest = PurchaseRequest::factory()->create([
            'department_id' => $ownDepartment->id,
            'requested_by' => $requester->id,
        ]);
        $foreignRequest = PurchaseRequest::factory()->create([
            'department_id' => $otherDepartment->id,
            'requested_by' => $requester->id,
        ]);
        $ownOrder = PurchaseOrder::factory()->create([
            'purchase_request_id' => $ownRequest->id,
            'created_by' => $requester->id,
        ]);
        $foreignOrder = PurchaseOrder::factory()->create([
            'purchase_request_id' => $foreignRequest->id,
            'created_by' => $requester->id,
        ]);

        $this->authorizeChain($head, $ownOrder)->assertOk();
        $this->authorizeChain($head, $foreignOrder)->assertForbidden();
    }

    public function test_subject_channels_and_unscoped_chain_channels_require_existing_records(): void
    {
        $adminRoleId = Role::query()->where('slug', 'system_admin')->value('id');
        $user = User::factory()->create(['role_id' => $adminRoleId]);
        $workOrder = WorkOrder::factory()->create();
        $machine = Machine::factory()->create();
        $period = PayrollPeriod::factory()->create();
        $missingId = app('hashids')->encode(999999999);

        foreach ([
            "private-production.wo.{$workOrder->hash_id}",
            "private-production.machine.{$machine->hash_id}",
            "private-payroll.period.{$period->hash_id}",
            "private-chain.work_order.{$workOrder->hash_id}",
        ] as $channel) {
            $this->authorizeChannel($user, $channel)->assertOk();
        }

        foreach ([
            "private-production.wo.{$missingId}",
            "private-production.machine.{$missingId}",
            "private-payroll.period.{$missingId}",
            "private-chain.work_order.{$missingId}",
        ] as $channel) {
            $this->authorizeChannel($user, $channel)->assertForbidden();
        }
    }

    public function test_payroll_progress_channel_matches_period_detail_access(): void
    {
        $user = $this->userWithPermissions('payroll.periods.view');
        $period = PayrollPeriod::factory()->create();

        $this->authorizeChannel($user, "private-payroll.period.{$period->hash_id}")->assertOk();
        $this->authorizeChannel($this->userWithPermissions(), "private-payroll.period.{$period->hash_id}")
            ->assertForbidden();

        app(SettingsService::class)->set('modules.payroll', false, 'modules');
        $this->authorizeChannel($user, "private-payroll.period.{$period->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.payroll', true, 'modules');
        $missingHash = app('hashids')->encode(999999999);
        $this->authorizeChannel($user, "private-payroll.period.{$missingHash}")->assertForbidden();
    }

    public function test_payroll_period_list_signal_matches_the_period_list_permission_and_feature(): void
    {
        $viewer = $this->userWithPermissions('payroll.periods.view');
        $other = $this->userWithPermissions();

        $this->authorizeChannel($viewer, 'private-entity.payroll_period.visible')->assertOk();
        $this->authorizeChannel($other, 'private-entity.payroll_period.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.payroll', false, 'modules');
        $this->authorizeChannel($viewer, 'private-entity.payroll_period.visible')->assertForbidden();
    }

    public function test_accounting_period_list_signal_matches_period_read_permission_and_feature(): void
    {
        $viewer = $this->userWithPermissions('accounting.periods.view');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($viewer, 'private-entity.accounting_period.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-entity.accounting_period.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.accounting', false, 'modules');
        $this->authorizeChannel($viewer, 'private-entity.accounting_period.visible')->assertForbidden();
    }

    public function test_payroll_adjustment_record_and_list_channels_match_api_read_access(): void
    {
        $viewer = $this->userWithPermissions('payroll.adjustments.view');
        $unauthorized = $this->userWithPermissions('payroll.periods.view');
        $period = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create();
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);
        $adjustment = PayrollAdjustment::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'original_payroll_id' => $payroll->id,
            'type' => 'underpayment',
            'amount' => '500.00',
            'reason' => 'Channel authorization regression',
            'created_by' => $viewer->id,
        ]);

        $recordChannel = "private-payroll.adjustment.{$adjustment->hash_id}";
        $listChannel = 'private-entity.payroll_adjustment.visible';
        $this->authorizeChannel($viewer, $recordChannel)->assertOk();
        $this->authorizeChannel($viewer, $listChannel)->assertOk();
        $this->authorizeChannel($unauthorized, $recordChannel)->assertForbidden();
        $this->authorizeChannel($unauthorized, $listChannel)->assertForbidden();
        $this->authorizeChannel($viewer, 'private-payroll.adjustment.unknown')->assertForbidden();

        app(SettingsService::class)->set('modules.payroll', false, 'modules');
        $this->authorizeChannel($viewer, $recordChannel)->assertForbidden();
        $this->authorizeChannel($viewer, $listChannel)->assertForbidden();
    }

    public function test_production_dashboard_channel_matches_dashboard_refresh_access(): void
    {
        $user = $this->userWithPermissions('production.view', 'production.dashboard.view');
        $dashboardPermissionOnly = $this->userWithPermissions('production.dashboard.view');
        $plantManager = $this->userWithPermissions('dashboard.plant_manager.view');
        $ppcViewer = $this->userWithPermissions('dashboard.ppc.view');
        $companyDashboardViewer = $this->userWithPermissions('dashboard.company.view');

        $this->authorizeChannel($user, 'private-production.dashboard')->assertOk();
        $this->authorizeChannel($dashboardPermissionOnly, 'private-production.dashboard')->assertForbidden();
        $this->authorizeChannel($plantManager, 'private-production.dashboard')->assertOk();
        $this->authorizeChannel($ppcViewer, 'private-production.dashboard')->assertOk();
        $this->authorizeChannel($companyDashboardViewer, 'private-production.dashboard')->assertOk();
        $this->authorizeChannel($this->userWithPermissions('dashboard.quality.view'), 'private-production.dashboard')->assertForbidden();

        app(SettingsService::class)->set('modules.production', false, 'modules');
        $this->authorizeChannel($user, 'private-production.dashboard')->assertForbidden();
    }

    public function test_quality_dashboard_channel_allows_only_quality_dashboard_readers(): void
    {
        $qualityViewer = $this->userWithPermissions('dashboard.quality.view');
        $unrelated = $this->userWithPermissions('dashboard.ppc.view');

        $this->authorizeChannel($qualityViewer, 'private-quality.dashboard')->assertOk();
        $this->authorizeChannel($unrelated, 'private-quality.dashboard')->assertForbidden();

        app(SettingsService::class)->set('modules.production', false, 'modules');
        $this->authorizeChannel($qualityViewer, 'private-quality.dashboard')->assertForbidden();
    }

    public function test_ppc_dashboard_refresh_channel_matches_its_api_permission(): void
    {
        $ppcViewer = $this->userWithPermissions('dashboard.ppc.view');
        $unrelated = $this->userWithPermissions('dashboard.quality.view');

        $this->authorizeChannel($ppcViewer, 'private-dashboard.ppc')->assertOk();
        $this->authorizeChannel($unrelated, 'private-dashboard.ppc')->assertForbidden();
    }

    public function test_employee_dashboard_channels_are_limited_to_linked_employee_accounts(): void
    {
        $employee = Employee::factory()->create();
        $owner = User::factory()->create(['employee_id' => $employee->id]);
        $otherEmployee = Employee::factory()->create();
        $other = User::factory()->create(['employee_id' => $otherEmployee->id]);
        $unlinked = User::factory()->create();

        $this->authorizeChannel($owner, 'private-dashboard.employee')->assertOk();
        $this->authorizeChannel($owner, "private-dashboard.employee.user.{$owner->hash_id}")->assertOk();
        $this->authorizeChannel($other, "private-dashboard.employee.user.{$owner->hash_id}")->assertForbidden();
        $this->authorizeChannel($unlinked, 'private-dashboard.employee')->assertForbidden();
    }

    public function test_plant_manager_dashboard_channel_is_feature_and_permission_scoped(): void
    {
        $plantManager = $this->userWithPermissions('dashboard.plant_manager.view');
        $unrelated = $this->userWithPermissions('dashboard.ppc.view');

        $this->authorizeChannel($plantManager, 'private-dashboard.plant_manager')->assertOk();
        $this->authorizeChannel($unrelated, 'private-dashboard.plant_manager')->assertForbidden();

        app(SettingsService::class)->set('modules.production', false, 'modules');
        $this->authorizeChannel($plantManager, 'private-dashboard.plant_manager')->assertForbidden();
    }

    public function test_production_work_order_channel_matches_detail_route_and_record(): void
    {
        $user = $this->userWithPermissions('production.view', 'production.work_orders.view');
        $workOrderOnly = $this->userWithPermissions('production.work_orders.view');
        $workOrder = WorkOrder::factory()->create();

        $this->authorizeChannel($user, "private-production.wo.{$workOrder->hash_id}")->assertOk();
        $this->authorizeChannel($workOrderOnly, "private-production.wo.{$workOrder->hash_id}")->assertForbidden();

        $missingHash = app('hashids')->encode(999999999);
        $this->authorizeChannel($user, "private-production.wo.{$missingHash}")->assertForbidden();
    }

    public function test_production_machine_channel_matches_machine_detail_route(): void
    {
        $user = $this->userWithPermissions('mrp.machines.view');
        $machine = Machine::factory()->create();

        $this->authorizeChannel($user, "private-production.machine.{$machine->hash_id}")->assertOk();

        app(SettingsService::class)->set('modules.mrp', false, 'modules');
        $this->authorizeChannel($user, "private-production.machine.{$machine->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.mrp', true, 'modules');
        $missingHash = app('hashids')->encode(999999999);
        $this->authorizeChannel($user, "private-production.machine.{$missingHash}")->assertForbidden();
    }

    public function test_machine_list_channel_requires_machine_view_permission_and_feature(): void
    {
        $viewer = $this->userWithPermissions('mrp.machines.view');
        $unauthorized = $this->userWithPermissions('production.view');

        $this->authorizeChannel($viewer, 'private-entity.machine.visible')->assertOk();
        $this->authorizeChannel($unauthorized, 'private-entity.machine.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.mrp', false, 'modules');
        $this->authorizeChannel($viewer, 'private-entity.machine.visible')->assertForbidden();
    }

    public function test_production_mold_channels_match_mold_read_permission_and_feature(): void
    {
        $viewer = $this->userWithPermissions('mrp.molds.view');
        $unauthorized = $this->userWithPermissions('mrp.machines.view');
        $product = Product::factory()->create();
        $mold = Mold::query()->create([
            'mold_code' => 'BROADCAST-MOLD',
            'name' => 'Broadcast mold',
            'product_id' => $product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots' => 100000,
        ]);

        $this->authorizeChannel($viewer, "private-production.mold.{$mold->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.mold.visible')->assertOk();
        $this->authorizeChannel($unauthorized, "private-production.mold.{$mold->hash_id}")->assertForbidden();
        $this->authorizeChannel($unauthorized, 'private-entity.mold.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.mrp', false, 'modules');
        $this->authorizeChannel($viewer, "private-production.mold.{$mold->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.mold.visible')->assertForbidden();
    }

    public function test_bom_channels_match_bom_read_permission_and_product_scope(): void
    {
        $viewer = $this->userWithPermissions('mrp.boms.view');
        $unauthorized = $this->userWithPermissions('mrp.molds.view');
        $product = Product::factory()->create();
        $bom = Bom::query()->create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $this->authorizeChannel($viewer, "private-production.bom.{$bom->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.bom.visible')->assertOk();
        $this->authorizeChannel($viewer, "private-mrp.product-bom.{$product->hash_id}")->assertOk();
        $this->authorizeChannel($unauthorized, "private-production.bom.{$bom->hash_id}")->assertForbidden();
        $this->authorizeChannel($unauthorized, 'private-entity.bom.visible')->assertForbidden();
        $this->authorizeChannel($unauthorized, "private-mrp.product-bom.{$product->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.mrp', false, 'modules');
        $this->authorizeChannel($viewer, "private-production.bom.{$bom->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.bom.visible')->assertForbidden();
        $this->authorizeChannel($viewer, "private-mrp.product-bom.{$product->hash_id}")->assertForbidden();
    }

    public function test_production_schedule_channels_match_scheduler_snapshot_access(): void
    {
        $viewer = $this->userWithPermissions('production.schedule.view');
        $other = $this->userWithPermissions('production.view');
        $workOrder = WorkOrder::factory()->create();
        $machine = Machine::factory()->create();
        $mold = Mold::query()->create([
            'mold_code' => 'AUTH-'.substr(uniqid(), -8),
            'name' => 'Schedule Channel Mold',
            'product_id' => $workOrder->product_id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $schedule = ProductionSchedule::query()->create([
            'work_order_id' => $workOrder->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHour(),
            'status' => ProductionScheduleStatus::Pending->value,
            'priority_order' => 1,
        ]);

        $this->authorizeChannel($viewer, "private-production_schedule.{$schedule->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.production_schedule.visible')->assertOk();
        $this->authorizeChannel($other, "private-production_schedule.{$schedule->hash_id}")->assertForbidden();
        $this->authorizeChannel($other, 'private-entity.production_schedule.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.mrp', false, 'modules');
        $this->authorizeChannel($viewer, "private-production_schedule.{$schedule->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.production_schedule.visible')->assertForbidden();
    }

    public function test_maintenance_dashboard_channel_requires_enabled_module_and_read_permission(): void
    {
        $user = $this->userWithPermissions('maintenance.view');
        $dashboardUser = $this->userWithPermissions('dashboard.maintenance.view');

        $this->authorizeChannel($user, 'private-maintenance.dashboard')->assertOk();
        $this->authorizeChannel($dashboardUser, 'private-maintenance.dashboard')->assertOk();
        $this->authorizeChannel($this->userWithPermissions(), 'private-maintenance.dashboard')->assertForbidden();

        app(SettingsService::class)->set('modules.maintenance', false, 'modules');
        $this->authorizeChannel($user, 'private-maintenance.dashboard')->assertForbidden();
        $this->authorizeChannel($dashboardUser, 'private-maintenance.dashboard')->assertForbidden();
    }

    public function test_inventory_dashboard_channel_requires_enabled_module_and_inventory_read_permission(): void
    {
        $user = $this->userWithPermissions('inventory.view');
        $noInventoryPermission = $this->userWithPermissions();

        $this->authorizeChannel($user, 'private-inventory.dashboard')->assertOk();
        $this->authorizeChannel($noInventoryPermission, 'private-inventory.dashboard')->assertForbidden();

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->authorizeChannel($user, 'private-inventory.dashboard')->assertForbidden();
    }

    public function test_dashboard_widget_refresh_channel_matches_widget_catalog_visibility(): void
    {
        DashboardWidget::query()->create([
            'key' => 'test.inventory_widget',
            'name' => 'Test Inventory Widget',
            'module' => 'inventory',
            'permission' => 'inventory.view',
            'render_kind' => 'scalar',
        ]);
        $viewer = $this->userWithPermissions('inventory.view');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($viewer, 'private-dashboard.widgets')->assertOk();
        $this->authorizeChannel($unrelated, 'private-dashboard.widgets')->assertForbidden();

        $viewer->update(['is_active' => false]);
        $this->authorizeChannel($viewer, 'private-dashboard.widgets')->assertUnauthorized();
    }

    public function test_warehouse_dashboard_refresh_channel_requires_warehouse_dashboard_permission(): void
    {
        $viewer = $this->userWithPermissions('dashboard.warehouse.view');
        $unrelated = $this->userWithPermissions('inventory.view');

        $this->authorizeChannel($viewer, 'private-dashboard.warehouse')->assertOk();
        $this->authorizeChannel($unrelated, 'private-dashboard.warehouse')->assertForbidden();
    }

    public function test_transfer_order_list_channel_requires_inventory_adjust_and_enabled_module(): void
    {
        $manager = $this->userWithPermissions('inventory.adjust');
        $reader = $this->userWithPermissions('inventory.view');

        $this->authorizeChannel($manager, 'private-entity.transfer_order.visible')->assertOk();
        $this->authorizeChannel($reader, 'private-entity.transfer_order.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->authorizeChannel($manager, 'private-entity.transfer_order.visible')->assertForbidden();
    }

    public function test_stock_adjustment_list_channel_matches_the_api_read_permissions(): void
    {
        $adjuster = $this->userWithPermissions('inventory.adjust');
        $approver = $this->userWithPermissions('inventory.adjust.approve');
        $stockReader = $this->userWithPermissions('inventory.view');

        $this->authorizeChannel($adjuster, 'private-entity.stock_adjustment.visible')->assertOk();
        $this->authorizeChannel($approver, 'private-entity.stock_adjustment.visible')->assertOk();
        $this->authorizeChannel($stockReader, 'private-entity.stock_adjustment.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->authorizeChannel($adjuster, 'private-entity.stock_adjustment.visible')->assertForbidden();
        $this->authorizeChannel($approver, 'private-entity.stock_adjustment.visible')->assertForbidden();
    }

    public function test_item_cost_review_list_channel_matches_the_api_read_permission(): void
    {
        $reviewer = $this->userWithPermissions('inventory.costs.review');
        $approverWithoutReadAccess = $this->userWithPermissions('inventory.costs.approve');
        $channel = 'private-entity.item_cost_review.visible';

        $this->authorizeChannel($reviewer, $channel)->assertOk();
        $this->authorizeChannel($approverWithoutReadAccess, $channel)->assertForbidden();

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->authorizeChannel($reviewer, $channel)->assertForbidden();
    }

    public function test_mrb_list_channel_matches_the_api_read_permission(): void
    {
        $reader = $this->userWithPermissions('inventory.mrb.view');
        $managerWithoutReadAccess = $this->userWithPermissions('inventory.mrb.manage');
        $channel = 'private-entity.material_review_record.visible';
        $location = WarehouseLocation::factory()->create();
        $record = new MaterialReviewRecord([
            'mrb_number' => 'MRB-CHANNEL-TEST',
            'item_id' => Item::factory()->create()->id,
            'quantity' => '1.000',
            'source_location_id' => $location->id,
            'quarantine_location_id' => $location->id,
            'held_by' => $reader->id,
            'held_at' => now(),
        ]);
        $record->status = 'held';
        $record->save();

        $this->authorizeChannel($reader, $channel)->assertOk();
        $this->authorizeChannel($managerWithoutReadAccess, $channel)->assertForbidden();
        $this->authorizeChannel($reader, "private-inventory.mrb.{$record->hash_id}")->assertOk();
        $this->authorizeChannel($managerWithoutReadAccess, "private-inventory.mrb.{$record->hash_id}")->assertForbidden();
        $missingId = app('hashids')->encode(999999999);
        $this->authorizeChannel($reader, "private-inventory.mrb.{$missingId}")->assertForbidden();

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->authorizeChannel($reader, $channel)->assertForbidden();
    }

    public function test_material_issue_list_channel_matches_the_api_read_permission(): void
    {
        $reader = $this->userWithPermissions('inventory.issue.create');
        $stockViewer = $this->userWithPermissions('inventory.view');
        $channel = 'private-entity.material_issue_slip.visible';
        $slip = MaterialIssueSlip::query()->create([
            'slip_number' => 'MIS-CHANNEL-TEST',
            'issued_date' => now()->toDateString(),
            'issued_by' => $reader->id,
            'created_by' => $reader->id,
            'status' => 'issued',
            'total_value' => '0.00',
        ]);

        $this->authorizeChannel($reader, $channel)->assertOk();
        $this->authorizeChannel($stockViewer, $channel)->assertForbidden();
        $this->authorizeChannel($reader, "private-inventory.material-issue.{$slip->hash_id}")->assertOk();
        $this->authorizeChannel($stockViewer, "private-inventory.material-issue.{$slip->hash_id}")->assertForbidden();

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->authorizeChannel($reader, $channel)->assertForbidden();
    }

    public function test_production_routing_channels_match_api_permissions_and_feature_gate(): void
    {
        $reader = $this->userWithPermissions('production.view', 'production.routings.view');
        $missingModuleRead = $this->userWithPermissions('production.routings.view');
        $product = Product::factory()->create();
        $routing = ProductRouting::query()->create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'total_cycle_time' => '1.50',
        ]);

        $this->authorizeChannel($reader, 'private-entity.production_routing.visible')->assertOk();
        $this->authorizeChannel($missingModuleRead, 'private-entity.production_routing.visible')->assertForbidden();
        $this->authorizeChannel($reader, "private-production.routing.{$routing->hash_id}")->assertOk();
        $this->authorizeChannel($missingModuleRead, "private-production.routing.{$routing->hash_id}")->assertForbidden();
        $missingId = app('hashids')->encode(999999999);
        $this->authorizeChannel($reader, "private-production.routing.{$missingId}")->assertForbidden();

        app(SettingsService::class)->set('modules.production', false, 'modules');
        $this->authorizeChannel($reader, 'private-entity.production_routing.visible')->assertForbidden();
        $this->authorizeChannel($reader, "private-production.routing.{$routing->hash_id}")->assertForbidden();
    }

    public function test_purchase_order_chain_channel_uses_the_record_visibility_scope(): void
    {
        $user = $this->userWithPermissions('purchasing.view');
        $own = PurchaseOrder::factory()->create(['created_by' => $user->id]);
        $foreign = PurchaseOrder::factory()->create(['created_by' => User::factory()->create()->id]);

        $this->authorizeChain($user, $own)->assertOk();
        $this->authorizeChain($user, $foreign)->assertForbidden();
    }

    public function test_mrp_plan_and_run_channels_match_their_read_permissions_and_feature(): void
    {
        $planViewer = $this->userWithPermissions('mrp.plans.view');
        $runViewer = $this->userWithPermissions('mrp.runs.view');
        $unrelated = $this->userWithPermissions();
        $plan = MrpPlan::query()->create([
            'mrp_plan_no' => 'MC-'.uniqid(),
            'sales_order_id' => SalesOrder::factory()->create()->id,
            'version' => 1,
            'status' => 'active',
            'generated_by' => $planViewer->id,
            'generated_at' => now(),
        ]);
        MrpRun::query()->create([
            'run_at' => now(),
            'started_at' => now(),
            'heartbeat_at' => now(),
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $runViewer->id,
            'status' => 'completed',
            'summary' => [],
        ]);

        $this->authorizeChannel($planViewer, "private-mrp.plan.{$plan->hash_id}")->assertOk();
        $this->authorizeChannel($planViewer, 'private-entity.mrp_plan.visible')->assertOk();
        $this->authorizeChannel($runViewer, 'private-entity.mrp_run.visible')->assertOk();
        $this->authorizeChannel($unrelated, 'private-mrp.plan.'.$plan->hash_id)->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.mrp_plan.visible')->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.mrp_run.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.mrp', false, 'modules');
        $this->authorizeChannel($planViewer, "private-mrp.plan.{$plan->hash_id}")->assertForbidden();
        $this->authorizeChannel($runViewer, 'private-entity.mrp_run.visible')->assertForbidden();
    }

    public function test_purchase_order_list_signal_requires_the_list_permission_and_feature(): void
    {
        $channel = 'private-entity.purchase_order.visible';
        $viewer = $this->userWithPermissions('purchasing.view');

        $this->authorizeChannel($viewer, $channel)->assertOk();
        $this->authorizeChannel($this->userWithPermissions(), $channel)->assertForbidden();

        app(SettingsService::class)->set('modules.purchasing', false, 'modules');
        $this->authorizeChannel($viewer, $channel)->assertForbidden();
    }

    public function test_purchase_order_approver_can_authorize_the_detail_channel_without_list_permission(): void
    {
        $approver = $this->userWithPermissions('purchasing.po.approve');
        $purchaseOrder = PurchaseOrder::factory()->create();

        $this->authorizeChain($approver, $purchaseOrder)->assertOk();
    }

    public function test_purchase_request_chain_channel_uses_the_record_visibility_scope(): void
    {
        $owner = $this->userWithPermissions('purchasing.view');
        $own = PurchaseRequest::factory()->create(['requested_by' => $owner->id]);
        $foreign = PurchaseRequest::factory()->create();

        $this->authorizeChain($owner, $own, 'purchase_request')->assertOk();
        $this->authorizeChain($owner, $foreign, 'purchase_request')->assertForbidden();
    }

    public function test_work_order_chain_channel_requires_both_detail_route_permissions(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $routeUser = $this->userWithPermissions('production.view', 'production.work_orders.view');
        $modulePermissionOnly = $this->userWithPermissions('production.work_orders.view');

        $this->authorizeChain($routeUser, $workOrder, 'work_order')->assertOk();
        $this->authorizeChain($modulePermissionOnly, $workOrder, 'work_order')->assertForbidden();
    }

    public function test_work_order_list_channel_requires_both_list_route_permissions(): void
    {
        $routeUser = $this->userWithPermissions('production.view', 'production.work_orders.view');
        $modulePermissionOnly = $this->userWithPermissions('production.work_orders.view');

        $this->authorizeChannel($routeUser, 'private-entity.work_order.visible')->assertOk();
        $this->authorizeChannel($modulePermissionOnly, 'private-entity.work_order.visible')->assertForbidden();
    }

    public function test_inactive_user_cannot_authorize_generic_entity_channel_via_legacy_endpoint(): void
    {
        $activeReader = $this->userWithPermissions('production.view', 'production.work_orders.view');
        $this->actingAs($activeReader)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-entity.work_order.visible',
            ])
            ->assertOk();

        $inactiveReader = $this->userWithPermissions('production.view', 'production.work_orders.view');
        $inactiveReader->forceFill(['is_active' => false])->save();

        $this->actingAs($inactiveReader)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-entity.work_order.visible',
            ])
            ->assertUnauthorized();
    }

    public function test_delivery_chain_channel_accepts_the_narrow_detail_route_permission(): void
    {
        $user = $this->userWithPermissions('supply_chain.deliveries.view');
        $salesOrder = SalesOrder::factory()->create();
        $delivery = Delivery::create([
            'delivery_number' => 'DEL-CHAIN-'.uniqid(),
            'sales_order_id' => $salesOrder->id,
            'status' => 'scheduled',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->authorizeChain($user, $delivery, 'delivery')->assertOk();
    }

    public function test_supported_chain_channels_match_detail_permissions_and_require_live_records(): void
    {
        $cases = [
            ['sales_order', fn (User $user): Model => SalesOrder::factory()->create(['created_by' => $user->id]), ['crm.sales_orders.view']],
            ['work_order', fn (): Model => WorkOrder::factory()->create(), ['production.view', 'production.work_orders.view']],
            ['purchase_request', fn (User $user): Model => PurchaseRequest::factory()->create(['requested_by' => $user->id]), ['purchasing.view']],
            ['purchase_order', fn (User $user): Model => PurchaseOrder::factory()->create(['created_by' => $user->id]), ['purchasing.view']],
            ['delivery', function (User $user): Model {
                return Delivery::create([
                    'delivery_number' => 'DEL-CHAIN-'.uniqid(),
                    'sales_order_id' => SalesOrder::factory()->create()->id,
                    'status' => 'scheduled',
                    'scheduled_date' => now()->toDateString(),
                    'created_by' => $user->id,
                ]);
            }, ['supply_chain.view']],
            ['grn', fn (): Model => GoodsReceiptNote::factory()->create(), ['inventory.view']],
            ['bill', fn (): Model => Bill::factory()->create(), ['accounting.bills.view']],
            ['invoice', fn (): Model => Invoice::factory()->create(), ['accounting.invoices.view']],
        ];

        foreach ($cases as [$type, $makeRecord, $permissions]) {
            $authorized = $this->userWithPermissions(...$permissions);
            $unauthorized = $this->userWithPermissions();
            $record = $makeRecord($authorized);

            $this->authorizeChain($authorized, $record, $type)->assertOk();
            $this->authorizeChain($unauthorized, $record, $type)->assertForbidden();
        }
    }

    public function test_grn_refresh_channels_require_inventory_scope_and_live_module(): void
    {
        $grn = GoodsReceiptNote::factory()->create();
        $viewer = $this->userWithPermissions('inventory.view');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChain($viewer, $grn, 'grn')->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.grn.visible')->assertOk();
        $this->authorizeChain($unrelated, $grn, 'grn')->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.grn.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->authorizeChain($viewer, $grn, 'grn')->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.grn.visible')->assertForbidden();
    }

    public function test_item_quality_plan_channel_matches_item_history_api_scope(): void
    {
        $item = Item::factory()->create();
        $viewer = $this->userWithPermissions('inventory.view');
        $qualityEditorWithoutInventoryRead = $this->userWithPermissions('quality.specs.manage');
        $unrelated = $this->userWithPermissions();

        $this->authorizeChannel($viewer, "private-quality_plan.{$item->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.quality_plan.visible')->assertOk();
        $this->authorizeChannel($qualityEditorWithoutInventoryRead, "private-quality_plan.{$item->hash_id}")->assertForbidden();
        $this->authorizeChannel($qualityEditorWithoutInventoryRead, 'private-entity.quality_plan.visible')->assertForbidden();
        $this->authorizeChannel($unrelated, "private-quality_plan.{$item->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.quality_plan.visible')->assertForbidden();

        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        $this->authorizeChannel($viewer, "private-quality_plan.{$item->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.quality_plan.visible')->assertForbidden();
    }

    public function test_return_request_channels_match_detail_and_customer_only_api_scopes(): void
    {
        app(SettingsService::class)->set('modules.return_management', true, 'modules');
        $customerReturn = ReturnRequest::create([
            'rma_number' => 'RMA-CHANNEL-C-'.uniqid(),
            'type' => 'customer_return',
            'status' => 'draft',
        ]);
        $supplierReturn = ReturnRequest::create([
            'rma_number' => 'RMA-CHANNEL-S-'.uniqid(),
            'type' => 'supplier_return',
            'status' => 'draft',
        ]);
        $viewer = $this->userWithPermissions('return_management.view');
        $unrelated = $this->userWithPermissions();

        $customerServiceRole = Role::query()->where('slug', 'customer_service_officer')->firstOrFail();
        $viewPermission = Permission::query()->where('slug', 'return_management.view')->firstOrFail();
        $customerServiceRole->permissions()->syncWithoutDetaching([$viewPermission->id]);
        $customerOnly = User::factory()->create(['role_id' => $customerServiceRole->id]);

        $this->authorizeChannel($viewer, "private-return_request.{$customerReturn->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, "private-return_request.{$supplierReturn->hash_id}")->assertOk();
        $this->authorizeChannel($unrelated, "private-return_request.{$customerReturn->hash_id}")->assertForbidden();
        $this->authorizeChannel($customerOnly, "private-return_request.{$customerReturn->hash_id}")->assertOk();
        $this->authorizeChannel($customerOnly, "private-return_request.{$supplierReturn->hash_id}")->assertForbidden();

        $this->authorizeChannel($viewer, 'private-entity.return_request.all')->assertOk();
        $this->authorizeChannel($viewer, 'private-entity.return_request.customer')->assertForbidden();
        $this->authorizeChannel($customerOnly, 'private-entity.return_request.customer')->assertOk();
        $this->authorizeChannel($customerOnly, 'private-entity.return_request.all')->assertForbidden();

        app(SettingsService::class)->set('modules.return_management', false, 'modules');
        $this->authorizeChannel($viewer, "private-return_request.{$customerReturn->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.return_request.all')->assertForbidden();
    }

    public function test_return_case_channels_are_scoped_for_internal_roles_and_customer_accounts(): void
    {
        app(SettingsService::class)->set('modules.return_management', true, 'modules');
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        $customer = Customer::factory()->create(['is_active' => true]);
        $vendor = Vendor::factory()->create(['is_active' => true]);
        $customerCase = $this->makeCase('customer', User::factory()->create(), $customer->id);
        $supplierCase = $this->makeCase('supplier', User::factory()->create(), null, $vendor->id);
        $viewer = $this->userWithPermissions('return_management.view');
        $unrelated = $this->userWithPermissions('inventory.view');

        $customerServiceRole = Role::query()->where('slug', 'customer_service_officer')->firstOrFail();
        $viewPermission = Permission::query()->where('slug', 'return_management.view')->firstOrFail();
        $customerServiceRole->permissions()->syncWithoutDetaching([$viewPermission->id]);
        $customerOnly = User::factory()->create(['role_id' => $customerServiceRole->id]);

        $this->authorizeChannel($viewer, "private-return_case.{$customerCase->hash_id}")->assertOk();
        $this->authorizeChannel($viewer, "private-return_case.{$supplierCase->hash_id}")->assertOk();
        $this->authorizeChannel($unrelated, "private-return_case.{$customerCase->hash_id}")->assertForbidden();
        $this->authorizeChannel($customerOnly, "private-return_case.{$customerCase->hash_id}")->assertOk();
        $this->authorizeChannel($customerOnly, "private-return_case.{$supplierCase->hash_id}")->assertForbidden();
        $this->authorizeChannel($viewer, 'private-entity.return_case.all')->assertOk();
        $this->authorizeChannel($customerOnly, 'private-entity.return_case.customer')->assertOk();
        $this->authorizeChannel($customerOnly, 'private-entity.return_case.all')->assertForbidden();

        $portalUser = CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Scoped Case Contact',
            'email' => 'case-channel-'.uniqid().'@example.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
        $otherCustomer = Customer::factory()->create(['is_active' => true]);
        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.account.{$customer->hash_id}",
        ])->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.account.{$otherCustomer->hash_id}",
        ])->assertForbidden();

        app(SettingsService::class)->set('modules.return_management', false, 'modules');
        $this->authorizeChannel($viewer, "private-return_case.{$customerCase->hash_id}")->assertForbidden();
    }

    public function test_customer_invoice_channel_is_private_to_active_portal_users_for_their_customer(): void
    {
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        app(SettingsService::class)->set('modules.return_management', false, 'modules');
        $customer = Customer::factory()->create(['is_active' => true]);
        $otherCustomer = Customer::factory()->create(['is_active' => true]);
        $portalUser = CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Invoice Portal Contact',
            'email' => 'invoice-channel-'.uniqid().'@example.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.invoice.{$customer->hash_id}",
        ])->assertOk()->assertJsonStructure(['auth']);

        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.invoice.{$otherCustomer->hash_id}",
        ])->assertForbidden();

        app(SettingsService::class)->set('modules.b2b_portals', false, 'modules');
        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.invoice.{$customer->hash_id}",
        ])->assertForbidden();
    }

    public function test_customer_order_channel_is_account_scoped_without_return_management_dependency(): void
    {
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        app(SettingsService::class)->set('modules.return_management', false, 'modules');
        $customer = Customer::factory()->create(['is_active' => true]);
        $otherCustomer = Customer::factory()->create(['is_active' => true]);
        $portalUser = CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Order Portal Contact',
            'email' => 'order-channel-'.uniqid().'@example.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.order.{$customer->hash_id}",
        ])->assertOk()->assertJsonStructure(['auth']);

        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.order.{$otherCustomer->hash_id}",
        ])->assertForbidden();

        app(SettingsService::class)->set('modules.b2b_portals', false, 'modules');
        $this->actingAs($portalUser, 'customer_portal')->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-customer.order.{$customer->hash_id}",
        ])->assertForbidden();
    }

    private function makeCase(string $type, User $creator, ?int $customerId = null, ?int $vendorId = null): ReturnCase
    {
        $case = new ReturnCase;
        $case->forceFill([
            'case_number' => 'CASE-CHANNEL-'.uniqid(),
            'type' => $type,
            'status' => 'submitted',
            'customer_id' => $customerId,
            'vendor_id' => $vendorId,
            'created_by' => $creator->id,
            'preferred_resolution' => 'advice',
            'description' => 'Broadcast scope test case.',
        ])->save();

        return $case;
    }

    public function test_chain_channel_denies_missing_records_and_disabled_modules(): void
    {
        $user = $this->userWithPermissions('crm.sales_orders.view');
        $salesOrder = SalesOrder::factory()->create();

        $this->authorizeChain($user, $salesOrder, 'sales_order')->assertOk();
        app(SettingsService::class)->set('modules.crm', false, 'modules');
        $this->authorizeChain($user, $salesOrder, 'sales_order')->assertForbidden();

        app(SettingsService::class)->set('modules.crm', true, 'modules');
        $missingHash = app('hashids')->encode(999999999);
        $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-chain.sales_order.{$missingHash}",
        ])->assertForbidden();
    }

    public function test_production_readiness_channel_requires_the_matching_lifecycle_permission(): void
    {
        $authorized = $this->userWithPermissions('production.work_orders.lifecycle');
        $unauthorized = $this->userWithPermissions('production.work_orders.view');

        $this->authorizeChannel($authorized, 'private-production.readiness')->assertOk();
        $this->authorizeChannel($unauthorized, 'private-production.readiness')->assertForbidden();

        app(SettingsService::class)->set('modules.production', false, 'modules');
        $this->authorizeChannel($authorized, 'private-production.readiness')->assertForbidden();
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $role = Role::create([
            'name' => 'Broadcast test '.substr(uniqid(), -5),
            'slug' => 'broadcast_test_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeChain(User $user, Model $record, string $type = 'purchase_order'): TestResponse
    {
        return $this->authorizeChannel($user, "private-chain.{$type}.{$record->hash_id}");
    }

    private function authorizeChannel(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }
}
