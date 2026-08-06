<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['inventory.grn_received.notification_roles', ['purchasing_officer'], 'GRN Received Notification Roles', 'Roles notified when goods are received.'],
            ['accounting.delivery_confirmed.notification_roles', ['finance_officer'], 'Delivery Confirmed Notification Roles', 'Roles notified when a delivery is confirmed.'],
            ['purchasing.purchase_request_approved.notification_roles', ['purchasing_officer'], 'Approved Purchase Request Notification Roles', 'Roles notified when a purchase request is approved.'],
            ['purchasing.purchase_order_approved.notification_roles', ['purchasing_officer'], 'Approved Purchase Order Notification Roles', 'Roles notified when a purchase order is approved.'],
            ['attendance.overtime_submitted.notification_roles', ['department_head'], 'Overtime Submission Notification Roles', 'Roles notified when overtime is submitted.'],
            ['leave.pending_hr.notification_roles', ['hr_officer'], 'Pending HR Leave Notification Roles', 'Roles notified when a leave request reaches HR approval.'],
            ['leave.submitted.notification_roles', ['department_head'], 'Leave Submission Notification Roles', 'Roles notified when leave is submitted.'],
            ['loans.submitted.notification_roles', ['finance_officer'], 'Loan Submission Notification Roles', 'Roles notified when an employee loan is submitted.'],
        ] as [$key, $roles, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($roles),
                'group' => explode('.', $key, 2)[0],
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'inventory.grn_received.notification_roles',
            'accounting.delivery_confirmed.notification_roles',
            'purchasing.purchase_request_approved.notification_roles',
            'purchasing.purchase_order_approved.notification_roles',
            'attendance.overtime_submitted.notification_roles',
            'leave.pending_hr.notification_roles',
            'leave.submitted.notification_roles',
            'loans.submitted.notification_roles',
        ])->delete();
    }
};
