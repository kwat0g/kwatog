<?php

declare(strict_types=1);

namespace App\Common\Services;

/**
 * The list of notification types a user can switch on or off.
 *
 * Two things make this more than a constant array:
 *
 * 1. **Admins can reorder and relabel it.** `notifications.catalog` is an
 *    editable setting (seeded by migration 0412) so groups and wording can be
 *    changed without a deploy.
 * 2. **That editable copy is a snapshot, and snapshots go stale.** The seed
 *    froze whatever `defaults()` returned in June 2026. Every type shipped
 *    since — the whole quality escalation family, dunning, training expiry —
 *    existed in code, fired at users, and appeared nowhere in the preferences
 *    UI, so there was no way to mute any of them. `groups()` therefore treats
 *    the setting as *overrides* rather than as the whole truth: it renders the
 *    configured catalog, then appends any default type the snapshot is missing.
 *    A new type is reachable the moment it lands in `defaults()`.
 *
 * The union is one-directional on purpose. Types an admin has added stay;
 * labels an admin has edited win over the default wording; only genuinely
 * absent keys are filled in.
 */
final class NotificationCatalog
{
    /** @return array<int, array{title:string,hint:string,types:array<int,array{key:string,label:string,description:string}>}> */
    public function groups(): array
    {
        $configured = app(SettingsService::class)->get('notifications.catalog');

        if (! is_array($configured) || $configured === []) {
            return self::defaults();
        }

        return self::mergeMissingDefaults($configured);
    }

    /**
     * Every type key in the catalog, flattened.
     *
     * @return array<int, string>
     */
    public function typeKeys(): array
    {
        return self::flattenKeys($this->groups());
    }

    /**
     * Append default types that the configured catalog does not contain.
     *
     * @param array<int, mixed> $configured
     * @return array<int, array{title:string,hint:string,types:array<int,array{key:string,label:string,description:string}>}>
     */
    private static function mergeMissingDefaults(array $configured): array
    {
        $known = array_flip(self::flattenKeys($configured));

        // Index the configured groups by title so a missing type rejoins the
        // group it belongs to rather than piling into a catch-all.
        $byTitle = [];
        foreach ($configured as $index => $group) {
            if (is_array($group) && isset($group['title']) && is_string($group['title'])) {
                $byTitle[$group['title']] = $index;
            }
        }

        foreach (self::defaults() as $group) {
            $missing = array_values(array_filter(
                $group['types'],
                static fn (array $type): bool => ! isset($known[$type['key']]),
            ));

            if ($missing === []) {
                continue;
            }

            if (isset($byTitle[$group['title']])) {
                $target = $byTitle[$group['title']];
                $configured[$target]['types'] = array_merge(
                    $configured[$target]['types'] ?? [],
                    $missing,
                );

                continue;
            }

            $configured[]                 = ['title' => $group['title'], 'hint' => $group['hint'], 'types' => $missing];
            $byTitle[$group['title']]     = array_key_last($configured);
        }

        return array_values($configured);
    }

    /**
     * @param array<int, mixed> $groups
     * @return array<int, string>
     */
    private static function flattenKeys(array $groups): array
    {
        $keys = [];

        foreach ($groups as $group) {
            foreach ((is_array($group) ? $group['types'] ?? [] : []) as $type) {
                if (is_array($type) && isset($type['key']) && is_string($type['key'])) {
                    $keys[] = $type['key'];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return array<int, array{title:string,hint:string,types:array<int,array{key:string,label:string,description:string}>}> */
    public static function defaults(): array
    {
        return [
            ['title' => 'Chain 1 · Order to Cash', 'hint' => 'Sales orders through production, delivery, and invoicing', 'types' => [
                ['key' => 'chain.so_confirmed', 'label' => 'Sales order confirmed', 'description' => 'A sales order has been confirmed by the customer.'],
                ['key' => 'chain.in_process_qc_required', 'label' => 'In-process QC required', 'description' => 'A work order started and needs periodic in-process sampling.'],
                ['key' => 'production.wo_completed', 'label' => 'Work order completed', 'description' => 'A production work order has finished. Outgoing QC is next.'],
                ['key' => 'chain.outgoing_qc_required', 'label' => 'Outgoing QC required', 'description' => 'A finished work order needs AQL sampling before it can ship.'],
                ['key' => 'quality.inspection_failed', 'label' => 'QC inspection failed', 'description' => 'A quality inspection failed. An NCR may be required.'],
                ['key' => 'chain.delivery_drafted', 'label' => 'Delivery drafted', 'description' => 'Outgoing QC passed and a delivery draft is ready to pick and dispatch.'],
                ['key' => 'chain.delivery_confirmed', 'label' => 'Delivery confirmed', 'description' => 'A delivery has been confirmed and an invoice draft was created.'],
                ['key' => 'return.restocked', 'label' => 'Returned goods restocked', 'description' => 'Customer-returned goods were moved back into sellable stock. Warehouse should shelf and verify them.'],
            ]],
            ['title' => 'Chain 2 · Procure to Pay', 'hint' => 'Requests, purchase orders, and goods receipts', 'types' => [
                ['key' => 'inventory.grn_received', 'label' => 'Goods receipt created', 'description' => 'Goods have been received against a purchase order.'],
                ['key' => 'chain.incoming_qc_required', 'label' => 'Incoming QC required', 'description' => 'A goods receipt needs incoming inspection before stock is accepted.'],
                ['key' => 'inventory.low_stock', 'label' => 'Low stock alert', 'description' => 'An item fell below reorder point and an auto-PR was created.'],
                ['key' => 'chain.pr_approved', 'label' => 'Purchase request approved', 'description' => 'A purchase request has been fully approved.'],
                ['key' => 'chain.pr_auto_convert_skipped', 'label' => 'PR auto-conversion skipped', 'description' => 'An approved PR could not be auto-converted to a PO (missing supplier or price) and needs manual conversion.'],
                ['key' => 'chain.po_approved', 'label' => 'Purchase order approved', 'description' => 'A purchase order has been fully approved and is ready to send.'],
                ['key' => 'auto_po_pending', 'label' => 'Auto-PO awaiting approval', 'description' => 'A critical stock level raised a purchase order automatically. It needs approval.'],
                ['key' => 'purchasing.supplier_deterioration', 'label' => 'Supplier performance dropped', 'description' => 'A supplier’s rating fell sharply against its recent baseline.'],
                ['key' => 'return.shipped_to_vendor', 'label' => 'Returned goods shipped to vendor', 'description' => 'Supplier-returned goods were shipped back to the vendor. Purchasing should track the shipment and follow up on the credit.'],
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
                ['key' => 'recruitment.new_application', 'label' => 'New job application', 'description' => 'A candidate applied to an open requisition.'],
                ['key' => 'recruitment.bottleneck', 'label' => 'Recruitment bottleneck', 'description' => 'An application, interview, or job posting has been waiting long enough to require HR action.'],
                ['key' => 'training.expiry', 'label' => 'Training about to expire', 'description' => 'An employee certification is approaching its expiry date.'],
            ]],
            ['title' => 'Quality & compliance', 'hint' => 'NCRs, SPC, cost of poor quality, and IATF document control', 'types' => [
                ['key' => 'auto_ncr_created', 'label' => 'NCR auto-created', 'description' => 'A failed inspection raised an NCR automatically. Root cause and disposition are needed.'],
                ['key' => 'ncr.escalation', 'label' => 'NCR escalated', 'description' => 'An NCR breached its response SLA and was escalated.'],
                ['key' => 'ncr.recurrence', 'label' => 'Recurring non-conformance', 'description' => 'The same defect pattern has been detected again.'],
                ['key' => 'ncr.return_to_supplier', 'label' => 'NCR returned to supplier', 'description' => 'An NCR was dispositioned as return-to-supplier and needs Purchasing action.'],
                ['key' => 'spc_alert', 'label' => 'SPC control limit breach', 'description' => 'A measured dimension drifted outside its control limits.'],
                ['key' => 'effectiveness_due', 'label' => 'Corrective action review due', 'description' => 'A corrective action is due for its effectiveness review.'],
                ['key' => 'effectiveness_overdue', 'label' => 'Corrective action review overdue', 'description' => 'An effectiveness review has passed its due date.'],
                ['key' => 'document.review_due', 'label' => 'Controlled document review due', 'description' => 'A controlled document has reached its scheduled review date.'],
                ['key' => '8d.sla', 'label' => 'Customer complaint 8D SLA', 'description' => 'An 8D discipline on a customer complaint is approaching or past its deadline.'],
            ]],
            ['title' => 'Finance & accounting', 'hint' => 'Receivables and invoicing exceptions', 'types' => [
                ['key' => 'ar.dunning.escalation', 'label' => 'Overdue receivable escalated', 'description' => 'A customer invoice passed its final dunning stage.'],
                ['key' => 'invoice.auto_failed', 'label' => 'Automatic invoicing failed', 'description' => 'An invoice could not be generated from a confirmed delivery.'],
            ]],
            // Title matches the group already present in the seeded setting so
            // the merge drops mrp_run_completed into it rather than creating a
            // near-duplicate group beside it.
            ['title' => 'Maintenance & approvals', 'hint' => 'MRP runs, machine breakdowns, and approvals waiting on you', 'types' => [
                ['key' => 'mrp_run_completed', 'label' => 'Daily MRP run finished', 'description' => 'The scheduled MRP run completed. Shortages and auto-PRs are ready to review.'],
                ['key' => 'maintenance.breakdown', 'label' => 'Machine breakdown', 'description' => 'A machine has entered breakdown status and may have paused a work order.'],
                ['key' => 'approval_reminder', 'label' => 'Approval reminder', 'description' => 'You have a pending approval that has been waiting over 24 hours.'],
                ['key' => 'approval_escalation', 'label' => 'Approval escalation', 'description' => 'An approval you are responsible for has been escalated due to timeout.'],
            ]],
            ['title' => 'Security & administration', 'hint' => 'Changes to your own access', 'types' => [
                ['key' => 'permission.override', 'label' => 'Your permissions changed', 'description' => 'An administrator granted or revoked a permission on your account.'],
            ]],
            ['title' => 'Email delivery', 'hint' => 'Email failures that need an approved follow-up channel', 'types' => [
                ['key' => 'email.delivery_failed', 'label' => 'Email delivery failed', 'description' => 'An email could not be delivered. Review the linked record and contact the recipient through an approved channel.'],
                ['key' => 'supplier.dispatch_action_required', 'label' => 'Supplier dispatch action required', 'description' => 'An approved purchase order needs supplier notification or transmission confirmation.'],
            ]],
        ];
    }
}
