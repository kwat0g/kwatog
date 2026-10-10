<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTIONS = [
        'system_admin' => ['Full access to every module. Override gate via AuthServiceProvider.', 'Administers users, roles, settings, audit logs, sessions, backups and integrations; business approval chains still enforce their designated approvers and maker-checker rules.'],
        'vice_president' => ['Executive sign-off on high-value purchases, payments, loans and asset disposals.', 'Reviews financial statements, budgets, supplier quotations and customer complaints; provides executive approval for purchases, payments, loans, salary adjustments and asset disposals without running sourcing, payroll preparation or HR records.'],
        'hr_officer' => ['Manages employees, attendance, leave; sees sensitive HR data.', 'Manages employee records, recruitment, training, attendance, leave, loans and separations; prepares payroll and statutory submissions, maintains government contribution tables and reviews profile changes; submits salary changes for independent approval.'],
        'production_manager' => ['Oversees work orders, output, OEE.', 'Runs production work orders and schedules, records output and efficiency, reviews quality and customer complaints, approves company loans, salary changes and returns; reads planning, procurement, inventory, maintenance and assets needed for production.'],
        'ppc_head' => ['Production Planning & Control — owns the schedule and BOMs.', 'Owns production planning, BOMs, routings, machines, molds, forecasting and finished-good master data; creates and confirms work orders, schedules production, and reads returns, maintenance and assets needed for planning.'],
        'purchasing_officer' => ['Manages PRs, POs, vendor relationships.', 'Runs purchase requests, orders, RFQs, supplier selection and performance; receives purchased goods, tracks inbound shipments and supplier returns, and reads bills, vendors and outbound delivery status; financial approvals follow the designated workflow.'],
        'warehouse_staff' => ['Receives goods, issues materials, counts stock, manages warehouse map.', 'Manages inventory, warehouse locations, receipts, material issues, counts, picking and quarantine holds; receives returns, stages outbound deliveries and signs warehouse separation-clearance tasks; stock adjustments require independent approval.'],
        'qc_inspector' => ['Logs inspection results, raises NCRs, views RMAs.', 'Manages inspections, specifications, nonconformance, calibration and traceability; inspects returns and manages quarantine holds; reads inventory, customer complaints and supplier quotations needed for quality review.'],
        'maintenance_tech' => ['Executes maintenance work orders.', 'Creates and completes maintenance work orders, records downtime and machine condition, reads maintenance schedules, machines and assets, and signs maintenance separation-clearance tasks; does not assign work or manage schedules.'],
        'impex_officer' => ['Tracks imported shipments and customs documents.', 'Manages inbound shipments, customs documents, outbound dispatch, delivery confirmation and fleet; reads linked purchase requests and orders without creating or approving procurement documents.'],
        'department_head' => ['Owns their department\\u2019s needs: raises PRs; approves leaves, OT and loans for their department.', 'Reads department employee records, raises purchase requests, approves department leave, overtime, loans and returns, and signs department separation-clearance tasks; purchase approvals follow the financial workflow.'],
        'driver' => ['Delivery driver (self-scoped delivery view). Driver routes scope by driver_id FK — no module permissions required.', 'Reads assigned delivery runs and records delivery outcomes and proof; uses employee self-service without general logistics, inventory or procurement access.'],
    ];

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'hr.separation.view_all', 'name' => 'Review Company Clearances and Final Pay',
            'module' => 'hr_separation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'hr.clearance.work_company', 'name' => 'Work Own Department Clearance Tasks Company-wide',
            'module' => 'hr_separation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['purchasing.po.view_all' => 'Read All Purchase Orders',
            'purchasing.po.responses.resolve' => 'Resolve Supplier Purchase Order Responses',
            'supply_chain.shipments.view' => 'View Inbound Shipments and Import Documents'] as $slug => $name) {
            DB::table('permissions')->insertOrIgnore([
                'slug' => $slug, 'name' => $name, 'module' => str_starts_with($slug, 'supply_chain.') ? 'supply_chain' : 'purchasing', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->grant('supply_chain.shipments.view', 'supply_chain.shipments.manage', ['purchasing_officer']);
        $this->copyRestrictions('supply_chain.shipments.view', 'supply_chain.view', DB::table('roles')->where('slug', 'purchasing_officer')->pluck('id'));
        $this->grant('purchasing.po.view_all', 'purchasing.po.approve', ['purchasing_officer', 'finance_officer', 'vice_president', 'system_admin']);
        $this->grant('purchasing.po.view_all', 'purchasing.po.create', ['purchasing_officer', 'system_admin']);
        $this->grant('purchasing.po.responses.resolve', 'purchasing.po.create', ['purchasing_officer', 'system_admin']);
        $buyerIds = DB::table('roles')->whereIn('slug', ['purchasing_officer', 'system_admin'])->pluck('id');
        $this->copyRestrictions('purchasing.po.responses.resolve', 'purchasing.po.approve', $buyerIds);
        $this->copyRestrictions('purchasing.po.view_all', 'purchasing.po.approve', $buyerIds);
        $this->grant('hr.clearance.work_company', 'hr.clearance.sign', ['warehouse_staff', 'maintenance_tech', 'system_admin']);
        $this->grant('hr.separation.view_all', 'hr.separation.view', ['hr_officer', 'finance_officer', 'system_admin']);
        $this->grant('admin.gov_tables.manage', 'payroll.gov_tables.manage', ['hr_officer']);
        $hrId = DB::table('roles')->where('slug', 'hr_officer')->value('id');
        $financialIds = DB::table('permissions')->whereIn('slug', ['loans.write_off.request', 'loans.write_off.approve'])->pluck('id');
        DB::table('role_permissions')->where('role_id', $hrId)->whereIn('permission_id', $financialIds)->delete();
        Cache::forget("auth:role_perms:{$hrId}");
        foreach (['purchasing_officer' => ['purchasing.pr.approve', 'purchasing.po.approve', 'supply_chain.view'],
            'department_head' => ['purchasing.pr.approve']] as $role => $slugs) {
            $roleId = DB::table('roles')->where('slug', $role)->value('id');
            DB::table('role_permissions')->where('role_id', $roleId)
                ->whereIn('permission_id', DB::table('permissions')->whereIn('slug', $slugs)->select('id'))->delete();
            Cache::forget("auth:role_perms:{$roleId}");
        }
        DB::table('permissions')->where('slug', 'loans.write_off')->where('name', 'Write Off Loan')
            ->update(['name' => 'Cancel Pending Loans and Record Payments']);
        foreach (self::DESCRIPTIONS as $slug => [$before, $after]) {
            DB::table('roles')->where('slug', $slug)->where('description', $before)->update(['description' => $after]);
        }
    }

    private function grant(string $slug, string $source, array $roles): void
    {
        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
        $sourceId = DB::table('permissions')->where('slug', $source)->value('id');
        if (! $permissionId || ! $sourceId) return;
        $roleIds = DB::table('roles')->whereIn('slug', $roles)
            ->whereIn('id', DB::table('role_permissions')->where('permission_id', $sourceId)->select('role_id'))->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
        $this->copyRestrictions($slug, $source, $roleIds);
    }

    private function copyRestrictions(string $slug, string $source, $roleIds): void
    {
        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
        $sourceId = DB::table('permissions')->where('slug', $source)->value('id');
        if (! $permissionId || ! $sourceId) return;
        // Preserve user restrictions when splitting an existing responsibility.
        foreach (DB::table('user_permission_overrides')->where('permission_id', $sourceId)->where('type', 'revoke')
            ->whereIn('user_id', DB::table('users')->whereIn('role_id', $roleIds)->select('id'))->get() as $override) {
            DB::table('user_permission_overrides')->insertOrIgnore([
                'user_id' => $override->user_id, 'permission_id' => $permissionId, 'type' => 'revoke',
                'granted_by' => $override->granted_by, 'reason' => $override->reason, 'expires_at' => $override->expires_at,
                'deleted_at' => $override->deleted_at, 'created_at' => $override->created_at, 'updated_at' => $override->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', ['hr.separation.view_all', 'hr.clearance.work_company', 'purchasing.po.view_all', 'purchasing.po.responses.resolve', 'supply_chain.shipments.view'])->pluck('id');
        foreach (DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->pluck('role_id')->unique() as $roleId) {
            Cache::forget("auth:role_perms:{$roleId}");
        }
        DB::table('permissions')->whereIn('slug', ['hr.separation.view_all', 'hr.clearance.work_company', 'purchasing.po.view_all', 'purchasing.po.responses.resolve', 'supply_chain.shipments.view'])->delete();
        // Government-table access uses an existing capability; retain customized grants on rollback.
        foreach (self::DESCRIPTIONS as $slug => [$before, $after]) {
            DB::table('roles')->where('slug', $slug)->where('description', $after)->update(['description' => $before]);
        }
    }
};
