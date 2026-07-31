<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODULES = [
        'hr' => 'Human Resources', 'attendance' => 'Attendance', 'leave' => 'Leave Management',
        'payroll' => 'Payroll', 'loans' => 'Loans', 'accounting' => 'Accounting',
        'inventory' => 'Inventory', 'purchasing' => 'Purchasing', 'crm' => 'CRM',
        'mrp' => 'MRP / MRP II', 'production' => 'Production', 'supply_chain' => 'Supply Chain',
        'quality' => 'Quality', 'maintenance' => 'Maintenance', 'assets' => 'Assets',
        'search' => 'Global Search', 'notifications' => 'Notifications', 'recruitment' => 'Recruitment',
        'return_management' => 'Return Management', 'b2b_portals' => 'B2B Portals',
        'forecasting' => 'Forecasting', 'budgeting' => 'Budgeting',
    ];

    public function up(): void
    {
        foreach (self::MODULES as $slug => $label) {
            DB::table('settings')->insertOrIgnore([
                'key' => "modules.{$slug}", 'value' => json_encode(true), 'group' => 'modules',
                'label' => $label, 'description' => "Enable or disable the {$label} module.",
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_map(fn ($slug) => "modules.{$slug}", array_keys(self::MODULES)))->delete();
    }
};
