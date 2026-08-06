<?php

declare(strict_types=1);

namespace App\Common\Services;

final class NotificationCatalog
{
    /** @return array<int, array{title:string,hint:string,types:array<int,array{key:string,label:string,description:string}>}> */
    public function groups(): array
    {
        $configured = app(SettingsService::class)->get('notifications.catalog');
        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return self::defaults();
    }

    /** @return array<int, array{title:string,hint:string,types:array<int,array{key:string,label:string,description:string}>}> */
    public static function defaults(): array
    {
        return [
            ['title' => 'Chain 1 · Order to Cash', 'hint' => 'Sales orders through production, delivery, and invoicing', 'types' => [
                ['key' => 'chain.so_confirmed', 'label' => 'Sales order confirmed', 'description' => 'A sales order has been confirmed by the customer.'],
                ['key' => 'production.wo_completed', 'label' => 'Work order completed', 'description' => 'A production work order has finished. Outgoing QC is next.'],
                ['key' => 'quality.inspection_failed', 'label' => 'QC inspection failed', 'description' => 'A quality inspection failed. An NCR may be required.'],
                ['key' => 'chain.delivery_confirmed', 'label' => 'Delivery confirmed', 'description' => 'A delivery has been confirmed and an invoice draft was created.'],
            ]],
            ['title' => 'Chain 2 · Procure to Pay', 'hint' => 'Requests, purchase orders, and goods receipts', 'types' => [
                ['key' => 'inventory.grn_received', 'label' => 'Goods receipt created', 'description' => 'Goods have been received against a purchase order.'],
                ['key' => 'inventory.low_stock', 'label' => 'Low stock alert', 'description' => 'An item fell below reorder point and an auto-PR was created.'],
                ['key' => 'chain.pr_approved', 'label' => 'Purchase request approved', 'description' => 'A purchase request has been fully approved.'],
                ['key' => 'chain.po_approved', 'label' => 'Purchase order approved', 'description' => 'A purchase order has been fully approved and is ready to send.'],
            ]],
            ['title' => 'Chain 3 · Hire to Retire', 'hint' => 'Leave, overtime, loans, and payroll', 'types' => [
                ['key' => 'leave.submitted', 'label' => 'Leave request submitted', 'description' => 'An employee has submitted a leave request for your approval.'],
                ['key' => 'leave.pending_hr', 'label' => 'Leave pending HR approval', 'description' => 'A leave request has been approved by the dept head and needs HR sign-off.'],
                ['key' => 'leave.approved', 'label' => 'Leave request approved', 'description' => 'Your leave request has been approved.'],
                ['key' => 'leave.rejected', 'label' => 'Leave request rejected', 'description' => 'Your leave request was not approved.'],
                ['key' => 'attendance.ot_submitted', 'label' => 'Overtime request submitted', 'description' => 'An employee has submitted an overtime request for your approval.'],
                ['key' => 'attendance.ot_approved', 'label' => 'Overtime request approved', 'description' => 'Your overtime request has been approved.'],
                ['key' => 'attendance.ot_rejected', 'label' => 'Overtime request rejected', 'description' => 'Your overtime request was not approved.'],
                ['key' => 'loans.submitted', 'label' => 'Loan/CA request submitted', 'description' => 'An employee has submitted a loan or cash advance for Finance approval.'],
                ['key' => 'loans.approved', 'label' => 'Loan/CA approved', 'description' => 'Your loan or cash advance request has been approved.'],
                ['key' => 'loans.rejected', 'label' => 'Loan/CA rejected', 'description' => 'Your loan or cash advance request was not approved.'],
                ['key' => 'chain.payslip_ready', 'label' => 'Payslip ready', 'description' => 'Your payslip is ready to view.'],
                ['key' => 'chain.separation_initiated', 'label' => 'Separation initiated', 'description' => 'An employee separation process has started.'],
            ]],
            ['title' => 'Maintenance & approvals', 'hint' => 'Machine breakdowns and approvals waiting on you', 'types' => [
                ['key' => 'maintenance.breakdown', 'label' => 'Machine breakdown', 'description' => 'A machine has entered breakdown status and may have paused a work order.'],
                ['key' => 'approval_reminder', 'label' => 'Approval reminder', 'description' => 'You have a pending approval that has been waiting over 24 hours.'],
                ['key' => 'approval_escalation', 'label' => 'Approval escalation', 'description' => 'An approval you are responsible for has been escalated due to timeout.'],
            ]],
        ];
    }
}
