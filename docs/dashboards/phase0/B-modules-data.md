# SECTION B — Modules & data (Phase 0 discovery)

Read-only research against PRIMARY SOURCES. Code wins over docs.
Branch: `docs/dss-discovery-phase0`. No code modified.

**Ground truth used:** live DB `ogami` (migrated schema: 261 tables), `api/app/Modules/*/Models`,
`api/app/Modules/*/Enums`, `api/app/Modules/*/Services`, `api/routes/console.php`,
`docs/SCHEMA.md` + `CLAUDE.md` (cross-check only — §5 lists where they are stale).

Conventions below: `path:line` = file + line in this checkout. Table columns quoted from
`information_schema` (i.e. post-migration reality, not migration intent).
Money = `decimal(15,2)` everywhere unless noted. PKs = bigint (HashIDs at API layer).

Related: `docs/dashboards/phase0/A-roles-access.md` (roles/permissions — not repeated here).

---

## 1. Per-module inventory

Format per module: models → physical tables (+ key columns) | status/type enums (exact values) |
cross-module FK relationships (table.column → target). FKs verified in `information_schema`;
"NO FK" flags an unenforced (convention-only) link.

### 1.1 HR — `api/app/Modules/HR/Models` (19 models)

| model → table | key columns (dashboard-relevant) |
|---|---|
| Employee → `employees` (soft del) | employee_no UQ, dept/position FKs, employment_type, pay_type, date_hired (idx), date_regularized, basic_monthly_salary, semi_monthly_rate, status (idx), encrypted sss/philhealth/pagibig/tin/bank_account_no |
| Department → `departments` (soft) | code UQ, parent_id (idx, self), head_employee_id (idx), is_active |
| Position → `positions` (soft) | (name/code/dept FK — standard master) |
| Clearance → `clearances` (soft) | clearance_no UQ, employee_id (idx), separation_date (idx), separation_reason, final_pay_computed/amount/breakdown(json), journal_entry_id → journal_entries, status (idx), initiated/finalized_by+at |
| EmploymentHistory → `employment_history` | employee_id+effective_date (idx), change_type (idx: hired/promoted/transferred/salary_adjusted/regularized/separated) |
| EmployeeSalaryHistory → `employee_salary_history` | employee_id+effective_date (idx) — salary trend source |
| SalaryAdjustment → `salary_adjustments` | status pending/approved/rejected |
| JobPosting/JobApplication/ApplicationInterview/ApplicationNote/RecruitmentApplicationEvent → `job_postings`, `job_applications`, `application_interviews`, `application_notes`, `recruitment_application_events` | recruitment funnel; stage enum new/screening/interview/offer/hired/rejected |
| Training/Skill/EmployeeTraining/EmployeeSkill/TrainingExpiryAlertDelivery → `trainings`, `skills`, `employee_trainings`, `employee_skills`, `training_expiry_alert_deliveries` | training matrix; expires_at (idx w/ status) |
| EmployeeDocument → `employee_documents` (soft) | employee_id (idx), document_type (idx) |
| EmployeeOnboarding → `employee_onboardings` | employee_id UQ |
| ProfileUpdateRequest → `profile_update_requests` | status pending/pending_finance/approved/rejected |

Enums (exact): EmployeeStatus active/on_leave/suspended/resigned/terminated/retired;
EmploymentType regular/probationary/contractual/project_based; PayType monthly/semi_monthly
(`daily` retired — migration 0437); Gender male/female; CivilStatus single/married/widowed/separated/divorced;
ClearanceStatus pending/in_progress/completed/finalized/cancelled; SeparationReason
resigned/terminated/retired/end_of_contract; EmploymentChangeType
hired/promoted/transferred/salary_adjusted/regularized/separated; SalaryAdjustmentStatus
pending/approved/rejected; JobPostingStatus draft/open/closed/filled; ApplicationStage
new/screening/interview/offer/hired/rejected; InterviewOutcome pending/passed/failed;
EmployeeTrainingStatus scheduled/completed/expired/cancelled; TrainingAlertLevel t30/t14/t7/expired;
TrainingMatrixStatus trained/expired/gap; EmployeeSkillLevel novice/competent/proficient/expert/trainer;
ProfileUpdateStatus pending/pending_finance/approved/rejected; PropertyStatus issued/returned/lost;
ReviewStatus pending/in_progress/submitted/acknowledged; PerformanceOverallRating /
PerformanceRatingCategory (display strings).

Cross-module: employees.department_id → departments.id, employees.position_id → positions.id;
clearances.journal_entry_id → Accounting.journal_entries.id; users.employee_id → employees.id (Auth);
attendances/payrolls/leave_requests/employee_loans/overtime.employee_id → employees.id;
maintenance_work_orders.assigned_to → employees.id; wo_operations.operator_id, production_logs.operator_id,
work_order_crews.recorder_employee_id → employees.id.

### 1.2 Attendance — `api/app/Modules/Attendance/Models` (6)

| model → table | key columns |
|---|---|
| Attendance → `attendances` (soft) | employee_id+date UQ (= emp_date idx), date (idx), shift_id → shifts (NO index, §4), time_in/out, regular_hours, overtime_hours, night_diff_hours, tardiness/undertime_minutes, holiday_type, is_rest_day, day_type_rate, status (idx), is_manual_entry |
| AttendancePunch → `attendance_punches` | employee_id+punched_at (idx), attendance_id (idx), direction in/out, geo (lat/long/accuracy/distance_m), ip/user_agent |
| Shift → `shifts` (soft) | name UQ, start/end_time, break_minutes, grace_minutes, is_night_shift, is_extended, auto_ot_hours, is_active, is_default (partial UQ) |
| EmployeeShiftAssignment → `employee_shift_assignments` | employee_id+effective_date UQ, shift_id (idx), end_date, working_days json |
| OvertimeRequest → `overtime_requests` | employee_id+date (idx; auto-source partial UQ), date, hours_requested, status (idx), is_auto_detected (idx w/ status), approved/cancelled_by+at |
| Holiday → `holidays` (soft) | date (idx + active partial UQ), type regular/special_non_working, is_recurring |

Enums: AttendanceStatus present/absent/late/halfday/on_leave/holiday/rest_day; PunchDirection in/out;
OvertimeStatus pending/approved/rejected; HolidayType regular/special_non_working;
DtrImportRowOutcome imported/noop/skipped_manual.

Cross-module: attendances.employee_id → HR.employees.id; leave_requests write attendance_snapshot +
balance_allocations json (Leave/LeaveRequestService.php:299,368); Payroll reads Attendance rows
(Payroll/PayrollCalculatorService.php:193-200).

### 1.3 Leave — `api/app/Modules/Leave/Models` (5)

| model → table | key columns |
|---|---|
| LeaveRequest → `leave_requests` (soft) | leave_request_no UQ, employee_id+status (idx), employee+start+half_day (idx), start_date+end_date (idx), days, status (idx), leave_type_id → leave_types (**NO index**, §4), dept/hr_approver+at, attendance_snapshot json, balance_allocations json, cancelled_by/at |
| EmployeeLeaveBalance → `employee_leave_balances` | employee+leave_type+year UQ, employee+year (idx), total_credits/used/**remaining (mutated in place)** |
| LeaveType → `leave_types` (soft) | code UQ, default_balance, is_paid, requires_document, convertible flags, conversion_rate, max_carryover_days, is_active |
| YearEndLeaveDisposition / ProcessedYearEndLeaveType → `year_end_leave_dispositions`, `processed_year_end_leave_types` | year-end conversion run ledger (→ payroll_adjustments FK from dispositions) |

Enums: LeaveRequestStatus pending_dept/pending_hr/approved/rejected/cancelled; LeaveHalfDayPeriod am/pm.

Cross-module: → HR.employees, → Attendance (snapshot), → Payroll (adjustments for encashment).

### 1.4 Payroll — `api/app/Modules/Payroll/Models` (11)

| model → table | key columns |
|---|---|
| PayrollPeriod → `payroll_periods` | period_start+period_end (idx), payroll_date, is_first_half (DERIVED — PayrollPeriod::deriveIsFirstHalf), is_thirteenth_month (partial UQ per year), status (idx: draft/processing/computed/approved/finalized/disbursed/voided), journal_entry_id → journal_entries (**NO index**, §4), scope_* json (employment types/depts/pay types), disbursed/voided/computed/approved/finalized_by+at, bank_file_status, gl_handoff_status (idx), processing_token, 13th-month run token/state, correction_* |
| Payroll → `payrolls` | payroll_period+employee UQ (dup idx pair), basic_pay, days_worked, overtime/night_diff/holiday/leave_pay, gross_pay, sss/philhealth/pagibig ee+er, withholding_tax, loan/other/adjustment deductions, total_deductions, net_pay, computed_at (idx w/ period), payslip email state (idx) |
| PayrollCycleClaim → `payroll_cycle_claims` | employee+cycle_key UQ (`YYYY-MM-H1|H2`), payroll_id UQ, period+cycle (idx) — the no-double-pay guard |
| PayrollDeductionDetail → `payroll_deduction_details` | payroll_id (idx), deduction_type (idx), amount, reference_id — **NO timestamps** (join payrolls for dates, §6-G1) |
| PayrollAdjustment → `payroll_adjustments` | period (idx), employee (idx), original_payroll_id (**NO index**), applied_to_payroll_id (**NO index**), type underpayment/overpayment, status (idx), applied_at |
| PayrollAnomalyFlag → `payroll_anomaly_flags` | payroll+flag_type UQ, period+is_resolved (idx), payroll_id (**NO index**), employee_id (**NO index**), flag_type, details json |
| ThirteenthMonthAccrual → `thirteenth_month_accruals` | employee+year UQ, year (idx), total_basic_earned, accrued_amount, is_paid, payroll_id (**NO index**) |
| BankFileRecord → `bank_file_records` | payroll_period_id (idx), format generic/bdo/bpi/metrobank, artifact_key UQ, record_count, total_amount, generated_by/at |
| DisbursementProof → `payroll_disbursement_proofs` (soft) | payroll_period_id (idx), proof_type, disbursement_date, amount |
| DeMinimisBenefit → `de_minimis_benefits` (soft) | employee+benefit_type+year+month+taxable UQ, payroll_id (**NO index**), amount |
| GovernmentContributionTable → `government_contribution_tables` (soft) | agency+effective_date+brackets UQ, agency+effective (idx), agency+is_active (idx), ee/er_amount, effective_date |

Enums: PayrollPeriodStatus draft/processing/computed/approved/finalized/disbursed/voided;
PayrollGlHandoffStatus not_started/pending/manual_required/posted/not_required;
BankFileGenerationStatus not_started/pending/manual_required/generated; BankFileFormat
generic/bdo/bpi/metrobank; DeductionType sss/philhealth/pagibig/withholding_tax/loan/cash_advance/
adjustment/thirteenth_month/other; ContributionAgency sss/philhealth/pagibig/bir;
PayrollAdjustmentType underpayment/overpayment; PayrollAdjustmentStatus pending/approved/rejected/applied;
PayrollAnomalyType large_change/excessive_ot/high_deduction/first_payroll/zero_pay/open_attendance;
PayrollHalfType (true=first); DeMinimisBenefitType rice_subsidy/uniform_allowance/medical_cash_allowance/
laundry_allowance/employee_achievement_award/gifts/meal_allowance_per_ot; DisbursementProofType
deposit_slip/bank_confirmation/transfer_receipt/other.

Cross-module: → HR.employees/clearances (separation exclusion w/ ClearanceStatus —
Payroll/PayrollPeriodService.php:613-623; EmployedDayFraction shared w/ HR FinalPayService),
→ Attendance.attendances, → Leave (leave_pay=0 note + encashment adjustments),
→ Loans (deduction + LoanPayment rows), → Accounting.journal_entries (GL post —
Payroll/PayrollGlPostingService.php:89-120,418-420; void reversal :1581-1632).

### 1.5 Loans — `api/app/Modules/Loans/Models` (2)

| model → table | key columns |
|---|---|
| EmployeeLoan → `employee_loans` | loan_no UQ, employee+loan_type (idx + open-type partial UQ: 1 loan + 1 CA rule), principal/interest_rate(0)/monthly_amortization/**total_paid+balance (mutated)**, start/end_date, pay_periods_total/**remaining (mutated)**, status (idx), write_off_* + write_off_journal_entry_id (**NO index**), disbursement_journal_entry_id (**NO index**), is_final_pay_deduction |
| LoanPayment → `loan_payments` | loan+payment_date (idx), loan+payroll deduction partial UQ, payroll_id (idx), clearance_id (idx, final-pay), payment_date, payment_type payroll_deduction/manual/final_pay, journal_entry_id (**NO index**), idempotency |

Enums: LoanType company_loan/cash_advance/sss_loan/pagibig_loan; LoanStatus
pending/active/paid/cancelled/rejected/write_off_pending/written_off; LoanPaymentType
payroll_deduction/manual/final_pay.

Cross-module: → HR.employees; → Payroll.payrolls (deduction) + Payroll.clearances (final-pay);
→ Accounting.journal_entries (disbursement + write-off).

### 1.6 Accounting incl. budgeting — `api/app/Modules/Accounting/Models` (23)

| model → table | key columns |
|---|---|
| Account → `accounts` | code UQ, type asset/liability/equity/revenue/expense (idx), normal_balance debit/credit, parent_id (idx), is_active |
| JournalEntry → `journal_entries` (soft) | entry_number UQ, date (idx), reference_type+reference_id (idx, **polymorphic — NO FK**, §2-F5), total_debit/credit, status (idx: draft/posted/reversed), posted_by/at, reversed_by_entry_id (idx), reversal_reason |
| JournalEntryLine → `journal_entry_lines` | journal_entry_id (idx), account_id (idx + acct+JE composite), line_no, debit/credit |
| AccountingPeriod → `accounting_periods` | year+month UQ, status (idx: open/closed/reopened), closed/reopened_by+at |
| Vendor → `vendors` (soft) | name (idx), is_active (idx), tin encrypted + tin_hash partial UQ, withholding_tax_type, payment_terms_days |
| Customer → `customers` (soft) | code UQ, name (idx), is_active (idx), tin encrypted, credit_limit, payment_terms_days |
| Bill → `bills` | vendor+bill_number UQ, vendor+supplier_invoice_number partial UQ, vendor_id (covered by UQ), purchase_order_id (idx), goods_receipt_note_id (idx), landed_cost_shipment_id (idx), date (idx), due_date (idx), subtotal/vat/total/**amount_paid+balance (mutated)**, status (idx: draft/unpaid/partial/paid/cancelled), journal_entry_id (**NO index**), department_id (**NO index**), supplier_invoice_id (**NO index**), provenance_type, three_way_match_snapshot json + override_*, has_variances, cancelled_* |
| BillItem → `bill_items` | bill_id (idx), expense_account_id (idx), item_id (idx), qty/unit_price/total — **NO timestamps** (§6-G1) |
| BillPayment → `bill_payments` | bill_id (idx), bill+idempotency partial UQ, payment_date (idx), amount, payment_method, cash_account_id (**NO index**), journal_entry_id (**NO index**), status (**NO index**: pending_approval/rejected/posted/voided), void/replacement chain, proof_* |
| SupplierInvoice → `supplier_invoices` | vendor+number live partial UQ, vendor_id (**NO index**), purchase_order_id (**NO index**), date, gross_total, portal_user_id (**NO index**), voided_* |
| Invoice → `invoices` | invoice_number UQ, customer_id (idx), sales_order_id (**NO index**), delivery_id (**NO index**), date (idx), due_date (idx), status+date+cancelled AR-history composite (idx), status (idx: draft/finalized/partial/paid/cancelled), dunning (status+due+tier idx), subtotal/vat/total/**amount_paid+balance**, journal_entry_id (**NO index**), vat_classification, prebill_*, lifecycle_type, cancelled_* |
| InvoiceItem → `invoice_items` | invoice_id (idx), revenue_account_id (idx), product_id (**NO FK/index — free product ref**), source_delivery_item_id (**NO index**), qty/unit_price/total — **NO timestamps** (§6-G1) |
| Collection (`InvoiceCollection`) → `collections` | invoice_id (idx), collection_date (idx), idempotency_key UQ, amount, cash_account_id (**NO index**), journal_entry_id (**NO index**), manual_reason |
| OfficialReceipt → `official_receipts` | or_number UQ, collection_id UQ+idx (1:1), customer_id (idx), invoice_id (**NO index**), date (idx), amount |
| InvoicePaymentSubmission → `invoice_payment_submissions` | customer+idempotency UQ, invoice pending partial UQ, customer+bank+ref UQ, collection_id UQ, invoice_id single (**NO index** — only pending-partial), submitted_by (**NO index**), status pending/verified/rejected, proof_* |
| CreditNote → `credit_notes` | credit_note_number UQ, type+status (idx), customer_id (idx), vendor_id (idx), invoice_id (**NO index**), bill_id (**NO index**), return_request_id (**NO index**), date (**NO index**), status single (**NO index**: draft/finalized/applied/void), subtotal/vat/total/applied/balance, journal_entry_id, void_* |
| CreditNoteLine → `credit_note_lines` | **only pkey — credit_note_id + account_id UNINDEXED** (§4) |
| CreditNoteApplication → `credit_note_applications` | credit_note_id (idx), operation_key UQ, invoice_id (**NO index**), bill_id (**NO index**) |
| Budget → `budgets` | **only pkey — fiscal_year_id + department_id + status UNINDEXED** (§4), fiscal_year_id, department_id, budget_type operating/capital/project/department, total_allocated/**spent+committed (mutated)**, submitted/approved/rejected_by+at |
| BudgetLineItem → `budget_line_items` | budget+account UQ, jan..dec month columns + annual_total, **actual_total+variance (synced — BudgetActualsSyncService)**, account_id |
| BudgetTransfer → `budget_transfers` | transfer_number UQ, status (idx: pending/approved/rejected), from/to_line_item_id (**NO index**), month, amount |
| BudgetActualsSyncRun → `budget_actuals_sync_runs` | sync run ledger |
| FiscalYear → `fiscal_years` | year UQ, start/end_date (gist no-overlap), status |

Enums: AccountType asset/liability/equity/revenue/expense; NormalBalance debit/credit;
JournalEntryStatus draft/posted/reversed; AccountingPeriodStatus open/closed/reopened;
BillStatus draft/unpaid/partial/paid/cancelled; BillPaymentStatus
pending_approval/rejected/posted/voided; InvoiceStatus draft/finalized/partial/paid/cancelled;
InvoicePaymentSubmissionStatus pending/verified/rejected; CreditNoteStatus
draft/finalized/applied/void; CreditNoteType customer/supplier; BudgetStatus
draft/submitted/approved/active/closed; BudgetTransferStatus pending/approved/rejected;
BudgetType operating/capital/project/department; PaymentMethod cash/check/bank_transfer/online;
VatClassification vatable/zero_rated/vat_exempt; WithholdingTaxType
none/goods/services/rentals/professional; StatementAgingBucket current/d1_30/d31_60/d61_90/d91_plus.

Cross-module: bills.purchase_order_id → Purchasing; bills.goods_receipt_note_id → Inventory;
invoices.sales_order_id → CRM + delivery_id → SupplyChain; collections→invoices; JEs posted by
Payroll/Inventory(Movement/GRN)/Production/Loans/HR-clearance/Billing/Collections —
via reference_type/reference_id convention + `Accounting/Services/SourceReferenceRegistry.php`.
BudgetEnforcementService gates PR/PO (Purchasing).

### 1.7 Inventory — `api/app/Modules/Inventory/Models` (22)

| model → table | key columns |
|---|---|
| Item → `items` (soft) | code UQ, category_id (idx), item_type (idx: raw_material/finished_good/packaging/spare_part), is_active/is_critical (idx), standard_cost, reorder_point/safety_stock (recomputed — SafetyStockRecomputeService), lead_time_days, abc_class, uom |
| ItemCategory → `item_categories` (soft) | type-inheriting tree |
| Warehouse/WarehouseZone/WarehouseLocation → `warehouses` (soft), `warehouse_zones` (soft), `warehouse_locations` (soft) | code UQs, zone wh+code UQ + type (idx), location zone_id (idx); location.current_item_id (**NO index**), current_quantity/lot, is_blocked |
| StockLevel → `stock_levels` | item+location UQ (= item idx + loc idx + item+qty idx), quantity/**reserved_quantity/weighted_avg_cost (MUTATED IN PLACE — StockMovementService ONLY writer, `Inventory/Services/StockMovementService.php:45-70`)**, last_counted_at, lock_version. **NO HasAuditLog** (verified §2-F11) |
| StockMovement → `stock_movements` | item+created (×2 idx), item+lot (idx), movement_type (idx: 14 values incl grn_receipt/material_issue/material_return/production_receipt/delivery(+return)/transfer/adjustment_in+out/scrap/return_to_vendor/cycle_count/opening), reference_type+reference_id (idx, **polymorphic — NO FK**, §2-F8), from/to_location_id (**NO index**), lot_number/expiry, unit/total_cost, journal_entry_id (**NO index**), gl_handoff_status (idx), idempotency UQ. **NO HasAuditLog** |
| GoodsReceiptNote → `goods_receipt_notes` | grn_number UQ, idempotency UQ, shipment_id UQ (1 GRN/shipment), purchase_order_id (idx), vendor_id (idx), supplier_shipment_id (**NO index**), received_date (idx), received_by (**NO index**), status (idx: draft/pending_qc/accepted/partial_accepted/rejected), qc_inspection_id (**NO index**, legacy single), accepted_by/at, journal_entry_id (**NO index**), incoming_qc_handoff_status (idx)+message+at |
| GrnItem → `grn_items` | goods_receipt_note_id (**NO index** — §4 HIGH), purchase_order_item_id (idx), item_id (idx), material_lot_number (idx), quantity_received/**accepted (mutated on accept)**, unit_cost, landed_cost_unit/total, UoM receipt cols, coa_verified, moisture, expiry |
| MaterialIssueSlip (+Item) → `material_issue_slips`, `material_issue_slip_items` | slip_number UQ, work_order_id (idx), issued_date (idx), status (idx: draft/issued/cancelled), issued_by/created_by (**NO index**); items: slip (idx), item (idx)+lot (idx), reservation (idx), stock_movement UQ, qty/cost |
| MaterialReservation → `material_reservations` | item (idx), work_order (idx), location_id (**NO index**), quantity, status (idx: reserved/issued/released), reserved/released_at |
| MaterialHandover → `material_handovers` | stock_movement UQ, receiver, handed_over_at |
| MaterialReviewRecord (MRB) → `material_review_records` | mrb_number UQ, return_request_item UQ, item (idx), status (idx: held/released/scrapped/returned), ncr_id (**NO index**), inspection_id (**NO index**), hold/release movements (**NO index**), quarantine/source/release locations (**NO index**), disposition |
| StockAdjustment → `stock_adjustments` | item+location (idx), reason_code (idx), status (idx: pending/approved/rejected/withdrawn), direction in/out, stock_movement_id (**NO index**), requested_by+idempotency UQ, approved/resolved_by+at |
| StockCountSession/StockCountItem → `stock_count_sessions`, `stock_count_items` | session_number UQ; session: warehouse_id (**NO index**), zone_id (**NO index**), status (**NO index**: draft/in_progress/completed/cancelled), frozen/completed_at; items: session+loc+item UQ, status (**NO index**), counted/verified_by+at (**NO index**) |
| TransferOrder → `transfer_orders` | transfer_number UQ; from/to_location_id (**NO index**), item_id (**NO index**), status (**NO index**: pending/transferred/completed/cancelled), transferred_by/at (**NO index**) |
| Uom/ItemUomConversion/ItemCategory/ItemCostReview/WarehouseScanEvent → `uoms`, `item_uom_conversions`, `item_cost_reviews`, `warehouse_scan_events` | conversion + cost-review + scan telemetry |

Enums: ItemType raw_material/finished_good/packaging/spare_part; GrnStatus
draft/pending_qc/accepted/partial_accepted/rejected; IncomingQcHandoffStatus
not_started/generated/manual_required/not_required; MovementGlHandoffStatus same-4;
StockMovementType (14, above); StockStatus critical/low/ok; StockAdjustmentDirection in/out;
StockAdjustmentReason cycle_count_variance/damage/expiry/theft/found_stock/system_correction;
StockAdjustmentStatus pending/approved/rejected/withdrawn; MaterialIssueStatus
draft/issued/cancelled; ReservationStatus reserved/issued/released; MrbStatus
held/released/scrapped/returned; StockCountSessionStatus draft/in_progress/completed/cancelled;
StockCountItemStatus pending/counted/verified/adjusted; StockCountScope full/warehouse/zone;
TransferOrderStatus pending/transferred/completed/cancelled; WarehouseZoneType
raw_materials/staging/finished_goods/spare_parts/quarantine/scrap; WarehouseScanContext
lookup/grn_id/material_issue_id/stock_count_session_id/wo_id; ItemCostReviewStatus
pending/approved/rejected; ReorderMethod fixed_quantity/days_of_supply.

Cross-module: grn.purchase_order_id → Purchasing; grn.shipment_id → SupplyChain;
qc_inspection_id + grn_items → Quality.inspections; moves post JEs (GrnGlPostingService,
MovementGlPostingService); material issues feed Production WOs; quarantine/MRB feeds Quality NCRs.

### 1.8 Purchasing — `api/app/Modules/Purchasing/Models` (18)

| model → table | key columns |
|---|---|
| PurchaseRequest → `purchase_requests` (soft) | pr_number UQ, requested_by (idx), department_id (idx), date (**NO index** — §4), required_delivery_date, priority (idx)/is_urgent, status (idx: draft/pending/approved/rejected/converted/cancelled), sourcing_method (idx: direct_po/rfq), is_auto_generated (idx), mrp_plan_id (idx), template_id (idx), stock_production_work_order_id (**NO index**), po_conversion_status (idx)+note+at, budget warning/ack, submitted/approved_at |
| PurchaseRequestItem → `purchase_request_items` (soft) | purchase_request_id (idx), item_id (idx), suggested_vendor_id (idx), qty/est price |
| PurchaseOrder → `purchase_orders` (soft) | po_number UQ, vendor_id (idx), purchase_request_id (idx), request_for_quote_id (idx), date (**NO index** — §4), expected_delivery_date (idx), confirmed_delivery_date, subtotal/vat/total, status (idx: 11 values), requires_vp_approval (idx), is_auto_generated (idx), pending_change_response_id (idx), rfq freight/vat/other carry-over, short_close_*, budget ack |
| PurchaseOrderItem → `purchase_order_items` (soft) | po+item (idx), purchase_request_item_id (idx), rfq_award_id + supplier_quote_version_id (trace idx), quantity/**received+accepted (mutated)**, unit_price/total + rfq line charges — **NO timestamps** (§6-G1) |
| RequestForQuote (+Item +Invitation) → `request_for_quotes`, `request_for_quote_items`, `request_for_quote_invitations` | rfq_number UQ, purchase_request_id (idx), status+closes_at (idx: draft/open/closed/awarded/cancelled), issued/closed/resolved_at; items: rfq+PR-item UQ, item_id (**NO index**); invitations: rfq+vendor UQ, vendor+status (idx) |
| SupplierQuote (+Item) → `supplier_quotes`, `supplier_quote_items` | rfq+vendor UQ, status (**NO index**: draft/submitted/awarded/not_awarded), invitation_id (**NO index**), portal_user_id (**NO index**), captured_by (**NO index**), submitted_at, vat/freight/total_delivered_cost, vat_treatment, quote_valid_until; items: quote+rfq-item UQ, request_for_quote_item_id (**NO index**), response_status (**NO index**), offered qty/price/lead time |
| RfqAward → `rfq_awards` | rfq line UQ (one winner/line), request_for_quote_id (**NO index**), vendor_id (**NO index**), supplier_quote_id/item_id (**NO index**), awarded qty/price/delivered_cost, awarded_by/at |
| RfqDocument → `rfq_documents` | rfq+vendor (idx), supplier_quote_id (**NO index**), document_type |
| ApprovedSupplier → `approved_suppliers` (soft) | item+vendor active UQ, item preferred partial UQ, is_preferred (idx), lead_time, last_price+at, listing link |
| SupplierItemListing → `supplier_item_listings` | vendor+status (idx), item+status (idx), status (idx: pending/approved/rejected/superseded), price, lead_time, valid_until |
| SupplierPerformanceSnapshot → `supplier_performance_snapshots` | vendor+year+month UQ, period (idx), period+tier (idx), overall_score (idx), OTD/quality/price/lead metrics, po/grn counts, tier |
| SupplierOrderDispatch → `supplier_order_dispatches` | purchase_order_id UQ, idempotency UQ, status+last_attempt (idx), channel/attempts |
| PurchaseOrderResponse (+Item) → `purchase_order_responses`, `purchase_order_response_items` | po pending_change_response_id (idx); portal_user_id (**NO index**); type accept/propose/decline; status pending/pending_approval/accepted/rejected/superseded |
| PurchaseRequestTemplate → `purchase_request_templates` (soft) | items json |

Enums: PurchaseRequestStatus draft/pending/approved/rejected/converted/cancelled;
PurchaseRequestPriority normal/urgent/critical; PurchaseRequestSourcingMethod direct_po/rfq;
PurchaseRequestConversionStatus not_started/pending/manual_required/partial/sourcing_pending/converted;
PurchaseOrderStatus draft/pending_approval/approved/sent/acknowledged/supplier_proposed/
supplier_declined/partially_received/received/closed/cancelled; PurchaseOrderResponseStatus
pending/pending_approval/accepted/rejected/superseded; PurchaseOrderResponseType
accept/propose/decline; RfqStatus draft/open/closed/awarded/cancelled; RfqInvitationStatus
invited/viewed/submitted/awarded/not_awarded; SupplierQuoteStatus
draft/submitted/awarded/not_awarded; SupplierQuoteResponseStatus quoted/no_quote;
SupplierDispatchStatus pending/portal_available/manual_required/confirmed/failed/cancelled;
SupplierListingStatus pending/approved/rejected/superseded; VatTreatment exclusive/inclusive/none.

Cross-module: PR.mrp_plan_id → MRP; PO.request_for_quote_id → RFQ; PO items carry
rfq_award_id (money computed ONCE in `Purchasing/Services/RfqCommercialCalculator.php`);
PO → SupplyChain.shipments (po idx), → Inventory GRNs (po idx), → Accounting.bills (po idx);
PR department → HR; ConsolidatePurchaseOrders listener auto-converts approved PRs
(`Purchasing/Listeners/ConsolidatePurchaseOrders.php`, wired in `App/Providers/AppServiceProvider.php`).

### 1.9 SupplyChain — `api/app/Modules/SupplyChain/Models` (19)

| model → table | key columns |
|---|---|
| Shipment → `shipments` (soft) | shipment_number UQ, purchase_order_id (idx), status (idx: ordered/shipped/in_transit/customs/cleared/received/cancelled), etd/atd/eta (idx)/ata/customs_clearance_date, carrier/vessel/container/bl, freight/insurance/duties/brokerage/other + landed_cost_total + allocation_method (by_value/by_weight/by_quantity/manual) + calculated_at, incoterm |
| Container → `containers` (soft) | container_number (idx), shipment_id (**NO index** — §4), size 20ft/40ft/40ftHC/45ft, type dry/reefer/open_top/flat_rack |
| ShipmentDocument → `shipment_documents` (soft) | 9 doc types (proforma/commercial/packing/BL/import_entry/COO/MSDS/BOC release/insurance), uploaded_by (**NO index**) |
| ShipmentLandedCost → `shipment_landed_costs` | shipment allocation lines |
| ShipmentLot → `shipment_lots` | lot_number UQ, delivery_id UQ (1 lot/delivery), customer_id (idx), product_id (**NO index**), work_order_ids json, lot_date (idx), coc_path — CoC evidence key |
| Vehicle → `vehicles` (soft) | type truck/van/motorcycle, status available/in_use/maintenance/retired |
| Delivery → `deliveries` (soft) | delivery_number UQ, sales_order_id (idx), vehicle_id (**NO index**), driver_id (**NO index**), invoice_id (**NO index**), status (idx: 8 values), scheduled_date (idx), departed/delivered/confirmed/received_at (**NO index** — §4), receiver_*, reschedule_count, idempotency UQ, coc_handoff_status+at, invoice_handoff_status (idx)+message+at, cost_recognition_mode (idx), expected_delivery_date, automatic_supply_key (idx) |
| DeliveryItem → `delivery_items` | delivery_id (idx), sales_order_item_id (idx), inspection_id (**NO index**), stock_movement UQ, sales_order_stock_allocation_id (**NO index**), quantity/unit_price/customer_received_quantity |
| DeliveryStockReservation (+Batch) → `delivery_stock_reservations`, `delivery_stock_reservation_batches` | batch: delivery UQ; lines: item+loc+lot+status (idx), delivery_item+status (idx), delivery_id (**NO index**), reservation_batch_id (**NO index**), stock_movement_id (**NO index**), qty/consumed/released, status reserved/consumed/released |
| DeliveryProof → `delivery_proofs` (soft) | delivery_id (idx), proof_type signed_dr/photo/loading_photo/customer_po_confirmation/coc/other, uploaded_by (**NO index**) |
| DeliveryAttemptOutcome (+Item +Movement +Revision) → `delivery_attempt_outcomes`, `delivery_attempt_outcome_items`, `delivery_attempt_outcome_movements`, `delivery_attempt_revisions` | outcome: delivery UQ, request/receipt keys UQ, return_request UQ, reported_at+by (idx); items: outcome+item UQ; movements: item+movement UQ + movement UQ; revisions: request_key UQ, outcome_id (**NO index**), return_request_id (**NO index**) |
| DeliveryQuantityDiscrepancy → `delivery_quantity_discrepancies` | delivery UQ, status (idx: pending/rejected) |
| DeliveryReschedule → `delivery_reschedules` | delivery_id (idx), rescheduled_by (**NO index**) |
| DeliveryCostHandoff → `delivery_cost_handoffs` | delivery+handoff_type read idx, request_key UQ, outcome_id (**NO index**), journal_entry_id (**NO index**) |
| DeliveryCustomerReturnAllocation → `delivery_customer_return_allocations` | return-item+source-movement+movement UQ, source+created (idx) |

Enums: ShipmentStatus ordered/shipped/in_transit/customs/cleared/received/cancelled;
ShipmentDocumentType (9 above); Incoterm EXW/FCA/FAS/FOB/CFR/CIF/CPT/CIP/DAP/DPU/DDP;
ContainerSize 20ft/40ft/40ftHC/45ft; ContainerType dry/reefer/open_top/flat_rack;
LandedCostAllocationMethod by_value/by_weight/by_quantity/manual; DeliveryStatus
scheduled/loading/in_transit/return_pending/delivered/confirmed/returned/cancelled;
DeliveryProofType signed_dr/photo/loading_photo/customer_po_confirmation/coc/other;
DeliveryAttemptReason customer_refused/customer_unavailable/access_restricted/vehicle_breakdown/
route_disruption/goods_damaged/other; DeliveryAttemptRevisionKind report_correction/late_recovery;
DeliveryStockReservationStatus reserved/consumed/released; DeliveryDiscrepancyStatus
pending/rejected; DeliveryCocHandoffStatus not_started/generated/not_required/manual_required;
DeliveryInvoiceHandoffStatus same-4; DeliveryCostHandoffStatus
generated/manual_required/not_required; DeliveryCostingMode legacy/transit; VehicleStatus
available/in_use/maintenance/retired; VehicleType truck/van/motorcycle.

Cross-module: shipments.purchase_order_id → Purchasing; GRN.shipment_id → shipments (UQ 1:1);
deliveries.sales_order_id → CRM + invoice_id → Accounting; delivery_items.inspection_id → Quality;
reservations/consumption → Inventory stock_movements; CoC via Quality CoCService +
ShipmentLotService.ensureForDelivery (`SupplyChain/Services/ShipmentLotService.php:110`).

### 1.10 Production — `api/app/Modules/Production/Models` (14)

| model → table | key columns |
|---|---|
| WorkOrder → `work_orders` (soft) | wo_number UQ, batch_number UQ, product_id (**NO index**), sales_order_id (idx), **sales_order_item_id (column exists, NO FK constraint at all — §2-F1)**, mrp_plan_id (idx), parent_wo_id (**NO index**, self), parent_ncr_id (no FK row), machine_id (idx), mold_id (**NO index**), bom_id (**NO index**), rework_source_output_id (**NO index**), quantity_target/**produced+good+rejected+scrap_rate (rollup-mutated)**, planned_start (idx)+status composite, planned/actual start+end (**actual_* NO index**), status (idx: planned/confirmed/in_progress/paused/completed/closed/cancelled), qc_workflow, production_purpose (idx), work_order_class, stock idempotency UQ, cost snapshot cols, crew min/max, preferred_production_date |
| WoOperation → `wo_operations` | **only deleted_at indexed besides pkey — work_order_id UNINDEXED (§4 HIGH)**; routing_operation_id (**NO index**), machine/mold/operator (**NO index**), status (NO index), planned/actual/setup times, qty_planned/**completed+scrapped (mutated)**, downtime_minutes |
| WorkOrderOutput → `work_order_outputs` | work_order+recorded_at (idx), work_order+idempotency UQ, recorded_at, good/reject_count, trial_inspection UQ, trial/held qtys + settled_at, production_started/finished_at, production_receipt_handoff_status (idx)+movement_id (idx → stock_movements), rework_ncr_id (**NO index**), rework_source_output_id (**NO index**), work_order_crew_id (**NO index**), receipt_unit_cost, crew_snapshot jsonb |
| WorkOrderMaterial → `work_order_materials` | work_order_id (idx), item_id (idx), bom_quantity/**actual_issued+variance (mutated)**, standard/actual cost + variance |
| WorkOrderDefect → `work_order_defects` | output_id (idx), defect_type_id (**NO index**), count — **NO timestamps** (§6-G1) |
| WorkOrderCrew (+Member) → `work_order_crews`, `work_order_crew_members` | assigned_by/recorder (**NO index**) |
| DefectType → `defect_types` | code UQ |
| MachineDowntime → `machine_downtimes` | machine+start_time (idx), category (idx), work_order_id (**NO index**), maintenance_order_id (**NO index**), start/end_time, duration_minutes |
| ProductionSchedule → `production_schedules` | work_order_id (idx), machine+start (idx), status (idx: pending/confirmed/superseded/executed), machine/mold gist no-overlap exclusion (reserves_capacity + blocking states), confirmed_by/at (**NO index**), rework_ncr_id (**NO index**), priority_order |
| ProductRouting/RoutingOperation → `product_routings`, `routing_operations` | product+version UQ + active partial UQ; routing_id (**NO index**), machine/mold (**NO index**), qc_required, rates |
| ProductionLog → `production_logs` | **only pkey — wo_operation_id + operator_id UNINDEXED (§4 HIGH)**; event_type (12 values), qty_value, recorded_at |
| LateProductionReport → `late_production_reports` | status pending/approved/rejected/correction; work_order_crew_id (**NO index**) |

Enums: WorkOrderStatus planned/confirmed/in_progress/paused/completed/closed/cancelled;
WoOperationStatus pending/setup/in_progress/paused/completed/skipped; ProductionLogEvent
start_setup/end_setup/start_production/pause/resume/record_output/record_scrap/end_production/skip/
downtime_start/downtime_end; ProductionScheduleStatus pending/confirmed/superseded/executed;
ProductionReceiptHandoffStatus not_started/generated/manual_required/not_required;
MachineDowntimeCategory breakdown/changeover/material_shortage/no_order/planned_maintenance;
LateProductionReportStatus pending/approved/rejected/correction.

Cross-module: product_id → CRM.products (NO FK — convention); machine_id → MRP.machines;
mold_id → MRP.molds; material issues → Inventory; outputs → stock_movements (receipt) +
Quality.inspections (outgoing, per-output UQ); NCR rework/replacement links (parent_ncr_id,
rework_source_output_id); `WorkOrder::coversSalesOrderLine()` =
sales_order_item_id NOT NULL AND parent_wo_id IS NULL
(`Production/Models/WorkOrder.php:120-123`) — THE ownership rule for replacement planning.

### 1.11 MRP — `api/app/Modules/MRP/Models` (7)

| model → table | key columns |
|---|---|
| MrpRun → `mrp_runs` | run_at (idx), status+run_at / status+started_at (idx: running/completed/partial/failed), triggered_by (scheduled/manual/automatic) + user (**NO index**), counters (orders evaluated/shortages/PRs/plans/failed), duration_ms, error_code/recovery_action, summary json — **order linkage only via summary json + mrp_plans bridge (§2-F6)** |
| MrpPlan → `mrp_plans` | mrp_plan_no UQ, sales_order_id+status (idx) + active partial UQ + version UQ, mrp_run_id (**NO index**), generated_by (**NO index**), generated_at, totals (lines/shortages/auto_PR/draft_WO), diagnostics + cost_summary + generation_context json, status active/superseded/cancelled |
| Bom → `bill_of_materials` (soft) | product+version UQ + active partial UQ, product+active (idx), material/labor/machine/overhead/total cost + basis/at/warnings/batch |
| BomItem → `bom_items` | bom_id (idx), bom+item UQ, item_id (idx), qty_per_unit/waste/cost cols |
| Machine → `machines` (soft) | machine_code UQ, status (idx: running/idle/maintenance/breakdown/offline), asset_id UQ → Assets, current_work_order_id (**NO index**), current_rework_ncr_id (**NO index**), tonnage/hours/capacity, running_hours_total (**mutated** — MachineHoursService) |
| Mold → `molds` (soft) | mold_code UQ, product_id (idx), status (idx: available/in_use/maintenance/retired), asset_id UQ, current_work_order_id / current_rework_ncr_id (**NO index**), **current_shot_count + lifetime_total_shots (mutated per output)**, max_shots_before_maintenance (80% alert), costs, commissioned/decommissioned/last_maintenance dates, maintenance_count |
| MoldHistory → `mold_history` | mold+event_date (idx), event_type (created/maintenance_*/shot_limit_reached/retired/repaired), shot_count_at_event, cost |

Enums: MrpRunStatus running/completed/partial/failed; MrpRunTrigger scheduled/manual/automatic;
MrpPlanStatus active/superseded/cancelled; MachineStatus running/idle/maintenance/breakdown/offline;
MoldStatus available/in_use/maintenance/retired; MoldEventType (7 above).

Cross-module: mrp_plans.sales_order_id → CRM; PRs created with mrp_plan_id (MRP/MrpEngineService.php:466-494);
draft WOs via WorkOrderService::createDraft (:632-645); schedules via CapacityPlanningService
(`MRP/Services/CapacityPlanningService.php:310` ProductionSchedule::create; Gantt read :588-609);
products live in **CRM** (`CRM/Models/Product.php`) though SCHEMA files them under MRP (§5-D12);
machines/molds ↔ Assets + Maintenance (MWOs) + Production.

### 1.12 CRM — `api/app/Modules/CRM/Models` (12)

| model → table | key columns |
|---|---|
| SalesOrder → `sales_orders` (soft) | so_number UQ, customer_id (idx), date (idx), subtotal/vat/total, status (idx: 11 values), mrp_plan_id (idx → MRP), sales_rep_id (**NO index**), return_case_id (UQ), submission_source (idx: internal/customer_portal), portal idempotency UQ, **8 stage timestamps ALL indexed** (confirmed/in_production/partially_delivered/delivered/invoiced/paid/closed/cancelled_at + customer_confirmation_requested_at), created_by (**NO index**) |
| SalesOrderItem → `sales_order_items` (soft) | sales_order_id (idx), product_id (idx), delivery_date (idx), requested_delivery_date, quantity/**delivered (mutated)**/unit_price/total, price_agreement_id (**NO index**), price_source/tier/date, fulfillment_policy — **NO timestamps** (§6-G1) |
| Product → `products` (soft) | part_number UQ (+upper UQ), is_active (idx), revenue_account_id (idx → Accounting), standard_cost, base/minimum_selling_price (**mutated**), include_forecast_in_mrp, portal_visibility, financial_change_request jsonb |
| PriceAgreement → `product_price_agreements` (soft) | product+customer+effective_from lookup idx, effective_to (idx), pricing_method flat/tiered, tiers json |
| PriceRequest → `price_requests` | customer+status (idx), customer+product open partial UQ, agreement_id (**NO index**), requested/proposed/reviewed_by (**NO/NO/NO index**), qty/prices, status (7 values), version, offer/history jsonb, cost_review_signature, responded/reviewed_at |
| CustomerComplaint → `customer_complaints` | complaint_number UQ, customer_id (idx + portal composites), product_id (idx), sales_order_id (**NO index**), ncr_id (**NO index**), replacement_work_order_id (**NO index**), credit_memo_id (no FK row), received_date (idx), severity (idx — NcrSeverity scale), status (idx: open/investigating/resolved/closed/cancelled), SLA due trio each w/ status (idx), sla_alert_levels jsonb, ncr_handoff_status (idx)+message+at, assigned/created_by (**NO index**), resolved/closed_at |
| Complaint8DReport → `complaint_8d_reports` | complaint_id UQ, d1..d8 text, finalized_by/at |
| SalesOrderResponse (+Item) → `sales_order_responses`, `sales_order_response_items` | so+status (idx), customer (idx), portal_user_id (**NO index**), proposed/resolved_by (**NO index**), type accept/propose/decline/delivery_date, status pending/accepted/rejected/superseded, proposed_delivery_date; items: sales_order_item_id (**NO index**) |
| SalesOrderStockAllocation → `sales_order_stock_allocations` | so-item+output+loc+request_key UQ, request_key (idx), inspection_id (**NO index**), item_id (**NO index**), allocated_by (**NO index**), qty/**transferred+consumed+released (mutated)**, status (NO index) |
| SalesOrderTransitionRejection → `sales_order_transition_rejections` | transition audit; requested_by (**NO index**) |

Enums: SalesOrderStatus draft/awaiting_confirmation/delivery_negotiation/confirmed/in_production/
partially_delivered/delivered/invoiced/paid/closed/cancelled; SalesOrderSubmissionSource
internal/customer_portal; SalesOrderResponseType accept/propose/decline/delivery_date;
SalesOrderResponseStatus pending/accepted/rejected/superseded; ComplaintStatus
open/investigating/resolved/closed/cancelled; ComplaintNcrHandoffStatus
not_started/generated/manual_required; PriceRequestStatus
requested/pending_finance/offered/accepted/rejected/withdrawn/expired; PricingMethod flat/tiered.

Cross-module: SO.customer_id → Accounting.customers; SO.mrp_plan_id → MRP; SO lines → products;
deliveries/invoices/return_cases/WOs keyed off SO (+items); complaints create NCRs
(`CRM/Services/ComplaintService.php:145,284` via NcrService::create); portal responses via B2B.

### 1.13 B2B — `api/app/Modules/B2B/Models` (8)

| model → table | key columns |
|---|---|
| SupplierPortalUser → `supplier_portal_users` (soft) | vendor_id (idx), email UQ, is_active (idx), lockout/password cols |
| CustomerPortalUser → `customer_portal_users` (soft) | customer_id (idx), email UQ, is_active (idx), lockout/password cols |
| SupplierShipment (+Item +Update) → `supplier_shipments`, `supplier_shipment_items`, `supplier_shipment_updates` | shipment: purchase_order_id (idx), portal_user_id (**NO index**), return_request_id (**NO index**), shipped/ETA dates; items: shipment+PO-item UQ; updates: portal_user_id (**NO index**) |
| PortalShippingDocument → `portal_shipping_documents` | po+type (idx), po+type+sha UQ, bill_id (**NO index**), supplier_invoice_id (**NO index**), supplier_shipment_id (**NO index**), uploaded_by (**NO index**), type commercial_invoice/packing_list/BL/CoA/dispatch_photo/other |
| DeliverySchedule → `delivery_schedules` | customer (idx), vendor (idx), purchase_order_id (idx), month (idx; customer+month partial UQ), status (idx: submitted/acknowledged/rejected/cancelled), lines json, reviewed_*, planned_delivery_date |

Enums: DeliveryScheduleStatus submitted/acknowledged/rejected/cancelled;
SupplierShippingDocumentType commercial_invoice/packing_list/bill_of_lading/
certificate_of_analysis/dispatch_photo/other; SupplierAgingBucket current/d1_30/d31_60/d61_90/d91_plus.

Cross-module: portal users → Accounting.vendors/customers; shipments/docs → Purchasing POs +
Inventory GRNs (supplier_shipment_id) + Accounting supplier_invoices; writes under non-web guard
go through SystemUserResolver::impersonate (audit-FK safety).

### 1.14 Forecasting — `api/app/Modules/Forecasting/Models` (1)

DemandForecast → `demand_forecasts`: product_id (**NO single index**), customer_id (**NO single index**),
forecast_year+month (idx), method, forecasted_quantity, confidence_level,
**actual_quantity+variance (BACKFILLED later — ForecastingService.php:251-302, §2-F7)**,
created_by (**NO index**); scope partial UQ (product+customer+year+month, nulls-not-distinct).

Enums: DemandSource forecast/historical/none; StockOutRisk critical/high/medium/low/ok.

Cross-module: product_id → CRM.products, customer_id → Accounting.customers;
ForecastMrpService gates on products.include_forecast_in_mrp
(`Forecasting/Services/ForecastMrpService.php:26,46`); StockOutProjectionService for risk.

### 1.15 ReturnManagement — `api/app/Modules/ReturnManagement/Models` (10)

| model → table | key columns |
|---|---|
| ReturnRequest (+Item) → `return_requests` (soft), `return_request_items` | request: rma_number UQ, source_key UQ, type (**NO index**: customer_return/supplier_return), status (**NO index**: 8 values), sales_order/invoice/PO/bill/customer/vendor/credit_note/debit_note/credit_memo/replacement_WO/replacement_PO/GRN/inspection/NCR/stock_movement/delivery_attempt_outcome (**ALL business FKs UNINDEXED** — §4 HIGH), reason_code, finance_only+approver (**NO index**), return_date (**NO index**), approved/received/inspected/completed/rejected/cancelled_at stage timestamps (**NO index**); items: request+receipt_recorded (idx), delivery_attempt_outcome_movement (idx); item/product/ncr/source_* (6 source FKs)/quarantine_*/stock_movement/split_parent (**ALL NO index**) |
| ReturnReceipt (+Item) → `return_receipts`, `return_receipt_items` | request+key UQ, request+received_at (idx), quarantine_location_id (**NO index**), received_by (**NO index**); items: receipt+item UQ, request_item (idx), stock_movement_id (**NO index**) |
| ReturnCase (+Line +Event +Attachment +ReceiptAllocation) → `return_cases`, `return_case_lines`, `return_case_events`, `return_case_attachments`, `return_case_receipt_allocations` | case_number UQ, creator+request_key UQ, type+status (idx), status+created (idx), customer/vendor/delivery/PO/GRN (idx), return_request UQ, assigned_to (idx), intake_kind (idx: discrepancy/delivery_trace), credit_note (**NO index**), replacement SO/PO/delivery (**NO index**), resolution GRN (**NO index**), portal users (**NO index**), resolved_at; lines: return_case_id (**NO index**); events/attachments: user/portal FKs (**NO index**) |
| ReturnRequestSourceAllocation → `return_request_source_allocations` | provenance splits |

Enums: ReturnRequestType customer_return/supplier_return; ReturnRequestStatus
draft/pending_approval/approved/received/inspected/completed/rejected/cancelled;
ReturnCaseType customer/supplier; ReturnCaseStatus submitted/under_review/information_needed/
action_agreed/in_progress/resolved/rejected/withdrawn; ReturnCaseIntakeKind
discrepancy/delivery_trace; ReturnCasePreferredResolution redelivery/credit/advice;
ReturnCaseResolution return_goods/redelivery/credit/no_action; ReturnCaseActorType
internal/customer/supplier/system; ReturnInspectionHandoffStatus
not_started/generated/manual_required/not_required; DispositionType
scrap/rework/restock/return_to_supplier/no_return.

Cross-module: the switchboard — touches CRM (SO/invoice), Purchasing (PO/bill),
Inventory (GRN/stock/quarantine), Quality (inspection/NCR), SupplyChain (delivery/attempts),
Accounting (credit/debit notes). Least-indexed fact tables in the system (§4).

### 1.16 Assets — `api/app/Modules/Assets/Models` (4)

| model → table | key columns |
|---|---|
| Asset → `assets` (soft) | asset_code UQ, category (idx: machine/mold/vehicle/equipment/furniture/other), department_id (idx → HR), acquisition_date (idx), acquisition_cost, useful_life_years, salvage_value, **accumulated_depreciation (mutated)**, depreciation_method straight_line/declining_balance, status (idx: active/under_maintenance/disposed), disposed_date/amount, disposal request_* + disposal_requested_by (**NO index**), insurance_* |
| AssetDepreciation → `asset_depreciations` | asset+year+month UQ, period (idx), depreciation_amount, accumulated_after, journal_entry_id (**NO index**) |
| AssetDepreciationRun → `asset_depreciation_runs` | run ledger |
| AssetTransfer → `asset_transfers` | transfer_number UQ, asset+status (idx), from/to_department_id (**NO index**), transfer_date, status pending/approved/rejected/completed, requested/approved_by (**NO index**) |

Enums: AssetCategory machine/mold/vehicle/equipment/furniture/other; AssetStatus
active/under_maintenance/disposed; DepreciationMethod straight_line/declining_balance;
TransferStatus pending/approved/rejected/completed.

Cross-module: machines.asset_id + molds.asset_id → assets.id (UQ 1:1); depreciation posts
Accounting JEs; departments → HR.

### 1.17 Quality — `api/app/Modules/Quality/Models` (18)

| model → table | key columns |
|---|---|
| Inspection → `inspections` | inspection_number UQ, stage (idx: incoming/in_process/outgoing/supplier_return/customer_return), status (idx + stage+status idx: draft/in_progress/awaiting_review/passed/failed/cancelled), product_id (idx), inspection_spec_id (**NO index**), spec_revision_id (**NO index**), item_id (**NO index**), item_quality_plan_id (**NO index**), grn_item_id (per-line stage UQ), work_order_output_id (outgoing-output UQ), return_request_item_id (per-line stage UQ), wo_operation_id (**NO index**), entity_type+entity_id legacy (idx; partial UQs only where typed FK null — §2-F9), batch/sample/aql/accept/reject/defect counts, inspector_id (idx), reviewed_by (**NO index**), started_at (**NO index**)/completed_at (idx), calibration_record_id (**NO index**), mode per_unit/lot_checklist, proposed/recorded_result + review trail, result_version + replaces_inspection UQ, coverage_confirmed_at |
| InspectionMeasurement → `inspection_measurements` | inspection+sample (idx), spec_item (idx), is_pass (idx), nominal/tolerance_min+max/measured_value, is_critical, item_result/problem/criterion |
| InspectionPieceReading → `inspection_piece_readings` | inspection+requirement+piece UQ, recorded_by (**NO index**) |
| InspectionDefectivePiece → `inspection_defective_pieces` | inspection+piece UQ, requirement_id (**NO index** → measurements), recorded_by (**NO index**) |
| InspectionResultAuthor → `inspection_result_authors` | inspection+user UQ, user (idx) |
| InspectionSpec (+Item +Revision) → `inspection_specs` (soft), `inspection_spec_items` (soft), `inspection_spec_revisions` | spec: product UQ, is_active (idx); items: spec (idx), parameter_type (idx), nominal/tolerances, is_critical, revision_id (**NO index**); revisions: spec+version UQ, spec+created (idx), created_by (**NO index**), capture_method |
| ItemQualityPlan → `item_quality_plans` | item+vendor+active lookup idx, item+vendor+version UQ, stage, sampling aql/fixed/full, created_by (**NO index**) |
| NonConformanceReport → `non_conformance_reports` | ncr_number UQ, inspection UQ-live (idx), complaint_id (idx), product_id (idx + recurrence idx), source (idx: **inspection_fail/customer_complaint**), severity (idx), status (idx: open/in_progress/closed/cancelled) + open+severity+escalated (idx), disposition (idx: scrap/rework/use_as_is/return_to_supplier), defect_signature+product+created (idx), closed_at (idx), replacement/rework_work_order_id (**NO index**), rework machine/mold (**NO index**), MRB/sorting/rework evidence cols, effectiveness_status+closed_at, recurrence_of_ncr_id (**NO index**), is_auto_generated (idx) |
| NcrAction → `ncr_actions` | ncr_id (idx) + ncr+action_type (idx), action_type (idx: containment/corrective/preventive), performed_at default-order (GROUP BY trap — must reorder()), due/owner/verification/effectiveness schedule (next_check idx) |
| NcrTemplate → `ncr_templates` (soft) | product_id (**NO index**) |
| NcrEscalationDelivery / NcrRecurrenceScan / NcrEffectivenessNotification → `ncr_escalation_deliveries`, `ncr_recurrence_scans`, `ncr_effectiveness_notifications` | escalation/SLA ledger (idempotency keys) |
| CalibrationRecord → `calibration_records` | equipment_code UQ, next_calibration_date (idx), status (idx: active/due/overdue/retired) |
| PpapSubmission (+Element) → `ppap_submissions`, `ppap_elements` | ppap_number UQ, vendor+item+status (idx), product_id (**NO index**), purchase_order_id (**NO index**), level 1-5, status (NO single index), submission/expiry dates; elements: submission+type UQ, status |

Enums: InspectionStage incoming/in_process/outgoing/supplier_return/customer_return;
InspectionStatus draft/in_progress/awaiting_review/passed/failed/cancelled; InspectionMode
per_unit/lot_checklist; InspectionOutcome passed/failed; InspectionParameterType
dimensional/visual/functional (**no Numeric — Dimensional IS the numeric path**);
InspectionEntityType grn/work_order/delivery/return_request; NcrSource
inspection_fail/customer_complaint; NcrStatus open/in_progress/closed/cancelled; NcrSeverity
low/medium/high/critical; NcrDisposition scrap/rework/use_as_is/return_to_supplier; NcrActionType
containment/corrective/preventive; EffectivenessStatus
pending_verification/effective/ineffective/not_applicable; CalibrationStatus
active/due/overdue/retired; PpapStatus draft/submitted/under_review/approved/rejected/expired;
PpapLevel 1-5; PpapElementType (19 values); PpapElementStatus
pending/submitted/accepted/rejected/not_applicable; QualityPlanSamplingMethod aql/fixed/full.

Cross-module: inspections → Inventory GRN lines, Production outputs/operations, Returns lines,
SupplyChain deliveries (delivery_items.inspection_id); NCRs → Production WOs (rework/replacement),
→ MRP (re-plan trigger), → Inventory MRB holds, → CRM complaints; CoC → SupplyChain lots;
calibration gates inspections; PPAP gates vendor+item supply.

### 1.18 Maintenance — `api/app/Modules/Maintenance/Models` (7)

| model → table | key columns |
|---|---|
| MaintenanceWorkOrder → `maintenance_work_orders` | mwo_number UQ, **maintainable_type+maintainable_id (idx; polymorphic machine/mold — NO FK)**, schedule_id (**NO index**), type (idx: preventive/corrective), priority (idx: critical/high/medium/low), status (idx: open/assigned/in_progress/completed/cancelled), assigned_to (idx → HR.employees), started/completed_at (**started NO index**, completed idx), downtime_minutes, cost, pre_maintenance_status, verification (required/submitted_by+at/verified_by+at), parts_reconciled_*, diagnosis/repair_action/functional_test |
| MaintenanceSchedule → `maintenance_schedules` (soft) | maintainable_type+id (idx), is_active (idx), next_due_at (idx), interval hours/days/shots + value, last_performed_at, running_hours_baseline, est duration |
| MaintenanceWindow → `maintenance_windows` | maintainable+state (idx), confirmed gist no-overlap + one-confirmed-per-WO UQ, planned start/end, confirmed_by (**NO index**) |
| MaintenanceLog → `maintenance_logs` | work_order+created (idx), logged_by (**NO index**), finding/action/result |
| MachineConditionReading → `machine_condition_readings` | machine+metric+recorded_at (idx), metric+recorded_at (idx), value/unit/source |
| MaintenancePartRequest → `maintenance_part_requests` | work_order+status (idx), operation_key UQ, work_log_id (**NO index** → logs), item_id (**NO index**), location_id (**NO index**), requested/withdrawn_by (**NO index**), handed_over_at |
| SparePartUsage → `spare_part_usage` | work_order_id (idx), item_id (idx), work_log_id (idx), operation_key UQ, issue_group_key (idx), stock_movement_id (**NO index**), qty/cost + returned_* |

Enums: MaintainableType machine/mold; MaintenanceWorkOrderType preventive/corrective;
MaintenanceWorkOrderStatus open/assigned/in_progress/completed/cancelled; MaintenancePriority
critical/high/medium/low; MaintenanceScheduleInterval hours/days/shots.

Cross-module: polymorphic to MRP.machines/molds; machine_downtimes.maintenance_order_id → MWOs;
part requests/usage → Inventory items/locations/stock_movements; assigned_to → HR.employees;
created/verified → Auth.users; breakeven analytics via DowntimeAnalyticsService + OeeService;
trigger: `Maintenance/Services/PredictiveMaintenanceService.php:47` recordAndEvaluate()
(reading → breach gate → corrective MWO via MaintenanceWorkOrderService::create :260).

### 1.19 Dashboard — `api/app/Modules/Dashboard/Models` (6)

| model → table | key columns |
|---|---|
| KpiDefinition → `kpi_definitions` | code UQ, module, unit percentage/count/currency/days/ratio, direction higher/lower_is_better, target_value, warning_threshold, calculation_method, is_active, display_order |
| KpiSnapshot → `kpi_snapshots` | definition+year+month UQ (**monthly grain ONLY** — computed 2nd @03:00 by `kpi:compute-monthly`), actual/target/previous_value, trend up/down/flat, status on_target/warning/off_target, breakdown json, computed_at (**NO index**) |
| DashboardWidget → `dashboard_widgets` | key UQ, module (idx), permission (who), render_kind scalar/breakdown/trend/table/gauge, link_path, (role defaults are UX seed, stripped at render by hasPermission) |
| DashboardLayout → `dashboard_layouts` | owner_type+owner_id (idx), widget_key (idx) |
| ActionCenterTask (+Event) → `action_center_tasks`, `action_center_task_events` | item_key UQ, state+snoozed (idx), assigned_to (idx); events: task_id (**NO index**), acted_by (**NO index**) |

Enums: KpiDirection higher_is_better/lower_is_better; KpiStatus on_target/warning/off_target;
KpiTrend up/down/flat; KpiUnit percentage/count/currency/days/ratio; RenderKind
scalar/breakdown/trend/table/gauge; ActionCategory alert/quality/maintenance/production/supply_chain.

Note: permission-derived (rarest-permission dispatch — DashboardDispatchService); drift guard
WidgetSeedIntegrityTest; NO role-name branches allowed.

### 1.20 Auth — `api/app/Modules/Auth/Models` (6) + Admin/LoginHistory

User → `users` (soft): email UQ, employee_id UQ+idx, role_id (+active+deleted lookup idx),
is_active, lockout/password-expiry cols. Role → `roles` (soft): slug UQ.
Permission → `permissions`. NotificationPreference, PasswordHistory, PasswordResetRequest.
Admin: LoginHistory → `login_history` (status: success/failed_credentials/failed_locked/
failed_inactive/failed_password_expired); UserPermissionOverride (soft); SodConflictRule.
Sanctum SPA cookie auth (no Bearer); audit actor on all financial writes.

Out of scope for dashboards but present: Admin (RBAC/audit consoles), Landing
(contact_inquiries/newsletter_subscribers).

---

## 2. The three chains traced in code (+ Quality & Maintenance tie-ins)

Event backbone: `App\Common\Services\OutboxService::recordForChain()` (`Common/Services/OutboxService.php:79`)
with chain names `'o2c'` (7 emit sites), `'p2p'` (14), `'h2r'` (8). Schedulers: 63
`Schedule::command()` entries in `api/routes/console.php` (retry/sweep/escalation commands below).

### 2.1 CHAIN 1 — Order to Cash

| # | stage → next | exact link (FK / event / call) |
|---|---|---|
| 1 | CRM Sales Order created | `CRM/Services/SalesOrderService.php:740` emits `SalesOrderConfirmed` on confirm; auto-listener stamps `sales_orders.mrp_plan_id` (:802) |
| 2 | SO → MRP Plan | FK `sales_orders.mrp_plan_id → mrp_plans.id`; engine reuses prior active plan (`MRP/Services/MrpEngineService.php:164`), creates plan row with `sales_order_id` (:308), links SO back (:669 `$so->update(['mrp_plan_id' => $plan->id])`) |
| 3 | MRP → PR (shortage leg) | `PurchaseRequest::create([... 'mrp_plan_id' => $plan->id])` (`MRP/Services/MrpEngineService.php:466-494`, is_auto_generated) |
| 4 | MRP → draft Work Orders | `WorkOrderService::createDraft(product+sales_order_id+sales_order_item_id+mrp_plan_id...)` (`MRP/Services/MrpEngineService.php:632-645` + subassembly WOs); stale planned WOs cancelled (:600-610) |
| 5 | MRP II schedule (capacity) | `ProductionSchedule::create()` pending rows (`MRP/Services/CapacityPlanningService.php:310`); machine/mold gist no-overlap exclusion; Gantt read (:588-609) |
| 6 | WO → material issue → output | `material_issue_slips.work_order_id`; `Production/Services/WorkOrderOutputService.php:603-720` `record()` (idempotent by work_order+idempotency_key) writes `work_order_outputs` + sets `production_receipt_movement_id → stock_movements.id` + handoff status |
| 7 | In-process QC | `inspections.wo_operation_id → wo_operations.id`; gate query in output path (`WorkOrderOutputService.php:117,261`); trial inspections (`:222-316`, trial_inspection_id UQ) |
| 8 | Outgoing QC | `inspections.work_order_output_id → work_order_outputs.id`, one-live-per-output (`inspections_outgoing_output_unique`); release gate `canReleaseOutgoing()` enforced at dispatch (`SupplyChain/Services/DeliveryService.php:893-921`); measurements vs tolerances (`Quality/Services/InspectionService.php` recordMeasurements) |
| 9 | FG → Delivery | delivery requires explicit passed output-bound inspection (`DeliveryService.php:848-921`); `delivery_items.inspection_id + sales_order_item_id`; stock via `delivery_stock_reservations` + `delivery_items.stock_movement_id` (UQ) |
| 10 | Delivery confirm → CoC + SO progress | `DeliveryService.php:1555` status→Confirmed; lot persist + `attachCertificatesOfConformance` (:1581-1609, via `SupplyChain/Services/ShipmentLotService.php:110` + Quality `CoCService`); `markDelivered/markPartiallyDelivered` (:1613-1627 → `CRM/Services/SalesOrderService.php:1091-1108`) |
| 11 | Confirm → draft Invoice (best-effort) | `createDraftInvoice` (:1629-1667); `deliveries.invoice_id → invoices.id` + `invoice_handoff_status` Generated / ManualRequired / NotRequired(no-charge replacement); `DeliveryConfirmed` outbox event (`:1694`, chain `o2c`) |
| 12 | Invoice → SO paid/closed | `InvoiceService::finalize()` calls `SalesOrderService::markInvoiced()` (`CRM/Services/SalesOrderService.php:76` comment, :1101); `synchronizeCompletionState` → Paid when every invoice Paid (:1121-1158); `invoice_items.source_delivery_item_id → delivery_items.id` |
| 13 | Collection → GL | `InvoiceCollection::create` + amount_paid/balance mutation (`Accounting/Services/InvoiceService.php:575,602-608`); `journal_entry_id` on invoice + collection; `official_receipts.collection_id` 1:1; dunning cols drive AR aging |

### 2.2 CHAIN 2 — Procure to Pay

| # | stage → next | exact link |
|---|---|---|
| 1 | MRP shortage → PR | §2.1-row-3 link; `purchase_requests.mrp_plan_id → mrp_plans.id` (idx) |
| 2 | PR approval (money-only chain; RFQ-sourced skips) | `PurchaseRequestService`; `PurchaseRequestApproved` event → `Purchasing/Listeners/ConsolidatePurchaseOrders.php` auto-converts to one draft PO/vendor |
| 3 | PR → RFQ → quotes → award → PO | `request_for_quotes.purchase_request_id`; invitations (rfq+vendor UQ); `supplier_quotes` (rfq+vendor UQ); `rfq_awards` (one winner/RFQ-line UQ); `AutoPurchaseOrderService.php:114` `PurchaseOrder::create`; `purchase_orders.request_for_quote_id`; line trace `purchase_order_items.rfq_award_id + supplier_quote_version_id` (`po_items_rfq_trace_idx`); money computed once in `Purchasing/Services/RfqCommercialCalculator.php` |
| 4 | PO → supplier | `supplier_order_dispatches.purchase_order_id` UQ; `supplier_shipments.purchase_order_id` (idx); `portal_shipping_documents` (po+type idx); PO change responses (`purchase_orders.pending_change_response_id`) |
| 5 | Shipment (ImpEx) | `shipments.purchase_order_id` (idx); `containers.shipment_id` (**FK, NO index** — §4); etd/atd/eta/ata/customs_clearance_date; `shipment_landed_costs` + allocation_method (`SupplyChain/Services/ShipmentService.php:37-56`, GRN-aware ctor) |
| 6 | Receive (GRN) | `goods_receipt_notes.purchase_order_id` (idx) + `shipment_id` UQ (≤1 GRN/shipment) + `supplier_shipment_id`; `grn_items.purchase_order_item_id`; `purchase_order_items.quantity_received/accepted` mutated; `refreshPoStatus` (`Inventory/Services/GrnService.php:676`) |
| 7 | Incoming QC | `GoodsReceiptNoteCreated` → Quality `TriggerIncomingQC` (`Quality/Listeners/TriggerIncomingQC.php`) creates stage=`incoming` inspections (`Inventory/Services/GrnService.php:1648,1901,1953`); per-line UQ `inspection_grn_line_stage_unique`; `grn.incoming_qc_handoff_status`; accept gated by `assertQcGate`; retry route + `grn:retry-pending-incoming-qc` sweeper (`api/routes/console.php`) |
| 8 | Accept → inventory + GL | `GrnService::accept()` moves accepted qty through `StockMovementService` (WAC recompute), posts via `GrnGlPostingService` atomically (`Inventory/Services/GrnService.php:826-840`) |
| 9 | Bill (3-way match) | `Bill::create` (`Accounting/Services/BillService.php:274,502`); `bills.purchase_order_id + goods_receipt_note_id + supplier_invoice_id`; `three_way_match_snapshot` via `Purchasing/Services/ThreeWayMatchService.php:38,309`; stock provenance requires supplier invoice before post/pay (`BillService.php:159-162`) |
| 10 | Payment → GL | `bill_payments.bill_id`; void→reversal JE + replacement chain; BIR-2307 (`Bir2307Service`) |

### 2.3 CHAIN 3 — Hire to Retire

| # | stage → next | exact link |
|---|---|---|
| 1 | Hire → profile + account | `employees` row → `HR/Services/UserProvisioningService.php:40` `provisionForEmployee` (`User::create` :57); `users.employee_id` UQ FK |
| 2 | Shift assignment | `employee_shift_assignments` (employee+effective UQ) → `shifts` |
| 3 | Biometric CSV → DTR | `Attendance/Services/DTRImportService.php:160` ingests punch-event CSV → `attendance_punches` → `DTRComputationService::computeForRecord` (:123,280) → `attendances` (UQ emp+date; recomputed in place; manual rows protected) |
| 4 | Leave/OT approvals | `Leave/LeaveRequestService.php:368` stamps `attendance_snapshot` on HR approval; `employee_leave_balances` mutated; `overtime_requests` (auto partial UQ emp+date) |
| 5 | Payroll compute | `Payroll/Services/PayrollCalculatorService.php:193-200` loads Attendance rows, aggregates OT/night/holiday (`:441+`); `Payroll/Support/EmployedDayFraction.php` shared with HR FinalPay (hire/separation proration); gov tables by `payroll_date`; loans decremented with `LoanPayment` rows; adjustments; 13th-month hook (`Payroll/Services/ThirteenthMonthService.php:84`) |
| 6 | Claims guard → finalize → disburse | `payroll_cycle_claims` UQ (emp+cycle_key); `PayrollPeriodService` finalize emits `PayrollGlPostingRequested` (`Payroll/Services/PayrollPeriodService.php:1446`) → `PayrollGlPostingService` posts JE (:89-120,418-420); void reverses JE (:1581-1632); `BankFileService` writes `BankFileRecord` CSV (:153-297) + disbursement proofs |
| 7 | Separation → clearance → final pay | `clearances` (employee, separation_date, breakdown json, JE); `HR/Services/FinalPayService.php:54` `compute()`; finalize posts JE; `loan_payments.clearance_id` (final_pay); active-clearance employees excluded from payroll scope (`PayrollPeriodService.php:613-623`); bank-file refuses final-pay-consumed periods (`Payroll/Services/BankFileService.php:96-125`) |

### 2.4 Quality tie-ins (all four touchpoints verified)

- **Incoming:** §2.2-row-7; `ItemQualityPlan` per item+vendor lookup (`item_quality_plan_lookup` idx); COA gate (`VerifyCoaOnIncomingQcPass` listener, `grn_items.coa_verified`); `assertQcGate` blocks accept.
- **In-process:** §2.1-row-7; `WorkOrderFinalInspectionService` (finish-first workflow).
- **Outgoing:** §2.1-row-8; AQL fields (sample_size/aql_code/accept/reject_count) + `inspection_measurements` actuals + `inspection_result_authors` review; CoC auto-attached from passed outgoing evidence (`Quality/Services/CoCService.php:287`, `DeliveryService.php:1581-1609`); missing-outgoing sweeper `qc:sweep-missing-outgoing` (hourly).
- **NCR loop:** auto-create on failed inspection / complaint (`Quality/Services/NcrService.php:182`; `CRM/Services/ComplaintService.php:145,284`); close creates **rework WO** (`NcrService.php:605-628`) or **replacement WO** (`:967-999`), SKIPPED when the failed WO covers an SO line (`:637,647,1090-1095` — MRP owns it instead); `QueueMrpOnOutgoingInspectionFailed` re-plans the SO (`MRP/Listeners/QueueMrpOnOutgoingInspectionFailed.php:18-36` run via `RunAutomaticMrpJob`); MRB holds (`material_review_records` hold/release movements); effectiveness (`EffectivenessService`), recurrence (`defect_signature` + `NcrRecurrenceDetector`), escalation ledger (`ncr_escalation_deliveries`), Pareto (`DefectParetoService`), SPC (`SpcService`); calibration gate (`inspections.calibration_record_id`); PPAP vendor/item gate.

### 2.5 Maintenance tie-ins

Breakdown categories on `machine_downtimes` → optional `maintenance_order_id → maintenance_work_orders.id`;
`PredictiveMaintenanceService::recordAndEvaluate()` (reading → breach → corrective MWO);
preventive `maintenance_schedules` (next_due_at idx) + `maintenance_windows` (confirmed no-overlap);
spares consume inventory (`spare_part_usage.stock_movement_id`); mold shot counters (`molds.current_shot_count`,
alert at 80% of max) with per-event detail in `mold_history`; analytics surfaces
(`DowntimeAnalyticsService`, Production `OeeService`) — the MTBF/MTTR/OEE dashboard sources.

### 2.6 Broken / missing / lossy links (flags, all verified)

- **F1 — WO→SO-line unenforced.** `work_orders.sales_order_item_id` exists but has **zero FK rows** in `information_schema` (verified). Orphan-able; dashboards must LEFT JOIN.
- **F2 — Delivery→Invoice lossy by design.** Confirm is valid with `invoice_handoff_status=manual_required` + `invoice_id NULL` (`DeliveryService.php:1640-1667`). Revenue dashboards must count handoff_status, not invoices alone.
- **F3 — GRN→incoming-QC event gap.** `pending_qc` GRNs with handoff ≠ generated have no inspection rows; sweeper exists because the gap is real (`grn:retry-pending-incoming-qc`).
- **F4 — Completed WOs without outgoing inspection existed as a class** (`qc:sweep-missing-outgoing`). O2C funnels must handle output batches with NULL inspection.
- **F5 — GL reference polymorphic.** `journal_entries.reference_type/reference_id` has NO FK (by-convention; registry at `Accounting/Services/SourceReferenceRegistry.php`). Same for `stock_movements.reference_type/reference_id` (F8) and MWOs' `maintainable_type/id`.
- **F6 — MRP run→order trace via bridge.** `mrp_runs` links orders only through `summary` json + `mrp_plans(mrp_run_id → sales_order_id)`; `mrp_plans.mrp_run_id` itself is unindexed.
- **F7 — Forecast actuals late-filled.** `demand_forecasts.actual_quantity/variance` backfilled by `ForecastingService.php:251-302`; only `IS NOT NULL` rows are comparable.
- **F8 — (see F5)** stock lineage by `StockMovementType` convention.
- **F9 — Inspections dual addressing.** Legacy `entity_type/entity_id` coexist with typed FKs; dashboards must prefer typed FKs (legacy UQs only fire when the typed FK IS NULL).
- **F10 — NCR-close WO creation can fail logged-only** (`NcrService.php:1124` Log::error). Replacement-coverage dashboards must reconcile `disposition=rework` + NULL `rework/replacement_work_order_id`.
- **F11 — No audit rows for stock.** `StockLevel` + `StockMovement` do NOT use `HasAuditLog` (verified grep=0; 136 other models do). Movement history = `stock_movements` ledger only.
- **F12 — Returns fact tables nearly unindexed** (`return_requests`: no status/type/date/customer/vendor indexes — §4).

---

## 3. Trend-ability — dates + history-vs-overwrite verdicts

**Rule used:** LEDGER/SNAPSHOT/STATUS-MACHINE = trendable from the table itself (stated grain);
OVERWRITE = point-in-time only (needs child ledger / `audit_logs` / reconstruction service);
MIXED = header mutated, children or versions preserve history (verdict names the fallback).

Key per-table dates (all tables also carry `created_at`; `updated_at` omitted unless load-bearing):

| table | date/timestamp cols | mechanism | verdict |
|---|---|---|---|
| stock_movements | created_at; expiry_date | append-only ledger; WAC engine `StockMovementService.php:45-70`; reads via `StockCardService` | **TRENDABLE-LEDGER** (grain: event) |
| stock_levels | updated_at, last_counted_at; NO created_at-independent history | qty/reserved/WAC mutated in place; NO audit rows (F11) | **OVERWRITE** — reconstruct from stock_movements |
| journal_entries + lines | date (idx), posted_at; lines: NO dates | posted immutable; reversed (not deleted); ref idx | **TRENDABLE-LEDGER** (date grain) |
| audit_logs | created_at (idx), model+created composite | append-only; 136 models | **TRENDABLE-LEDGER** (status-transition fallback) |
| sales_orders | date (idx) + 8 indexed stage timestamps | status machine, rows never deleted (soft) | **TRENDABLE-STATUS-MACHINE** (funnel by stage timestamps) |
| sales_order_items | delivery_date/requested_delivery_date (idx); **NO timestamps** | quantity_delivered mutated | **MIXED** — join sales_orders.date |
| work_orders | planned_start (idx); actual_start/end, preferred_production_date (**actual_* unindexed**) | status machine; qty rollups mutated | **TRENDABLE-STATUS-MACHINE** (lead-time needs actual_* index, §4) |
| wo_operations | planned/actual/setup start+end | qty_completed/scrapped mutated | **MIXED** — join work_orders; needs work_order_id index |
| work_order_outputs | recorded_at; production_started/finished_at; trial/held settled_at | append-only (idempotent key, not upsert) | **TRENDABLE-LEDGER** (recorded_at grain) |
| production_logs | recorded_at | append-only events | **TRENDABLE-LEDGER** |
| machine_downtimes | start_time (idx machine+start), end_time | append-only | **TRENDABLE-LEDGER** |
| inspections | started_at (unindexed), completed_at (idx), reviewed_at, coverage_confirmed_at | result_version + replaces chain; rows immutable once recorded_result set | **TRENDABLE-LEDGER** (completed_at grain) |
| inspection_measurements / piece_readings / defective_pieces | created_at only | append-only evidence | **TRENDABLE-LEDGER** (via inspections) |
| non_conformance_reports | created_at (in product+created idx), closed_at (idx), last_escalated_at | status machine; NCR-inspection live UQ | **TRENDABLE-STATUS-MACHINE** |
| ncr_actions | performed_at (default order), due_date, verified_at, next_check (idx) | append-only CAPA trail | **TRENDABLE-LEDGER** |
| deliveries | scheduled_date (idx); departed/delivered/confirmed/received_at (**unindexed**); expected_delivery_date; original_scheduled_date | status machine + handoff statuses | **TRENDABLE-STATUS-MACHINE** (OTD needs delivered/received indexes, §4) |
| delivery_items | created_at + updated_at present | rows immutable per delivery | **TRENDABLE-LEDGER** (via deliveries) |
| delivery_attempt_outcomes (+items/movements/revisions) | reported_at (idx), reconciled_at | append-only attempt chain (delivery UQ 1:1 current) | **TRENDABLE-LEDGER** |
| delivery_stock_reservations | created_at/updated_at (tz) | consumed/released mutated | **MIXED** — join deliveries |
| invoices | date (idx), due_date (idx), cancelled_at; status+date AR composite | amount_paid/balance mutated; children immutable | **TRENDABLE-STATUS-MACHINE** (AR aging ready) |
| invoice_items | **NO timestamps** | immutable per invoice | **MIXED** — join invoices.date |
| collections | collection_date (idx) | immutable payments | **TRENDABLE-LEDGER** |
| official_receipts | date (idx) | 1:1 with collections | **TRENDABLE-LEDGER** |
| bills | date (idx), due_date (idx), cancelled_at, supplier_invoice_date | amount_paid/balance mutated; snapshot json | **TRENDABLE-STATUS-MACHINE** |
| bill_items | **NO timestamps** | immutable per bill | **MIXED** — join bills.date |
| bill_payments | payment_date (idx), voided_at | immutable + void chain | **TRENDABLE-LEDGER** |
| credit_notes (+lines/+applications) | date (**unindexed**); lines have timestamps | applied/balance mutated | **MIXED** — needs date index (§4) |
| purchase_requests (+items) | date (**unindexed**); submitted/approved_at; po_conversion_at | status machine | **TRENDABLE-STATUS-MACHINE** (needs date index, §4) |
| purchase_orders | date (**unindexed**); expected/confirmed_delivery_date; approved/sent/short_closed_at | status machine; line qty_received/accepted mutated | **TRENDABLE-STATUS-MACHINE** (needs date index, §4) |
| purchase_order_items | **NO timestamps** | qty_received/accepted mutated | **MIXED** — join purchase_orders.date |
| request_for_quotes (+items/invitations) / supplier_quotes (+items) / rfq_awards | issued/closes/closed/resolved_at; submitted_at; awarded_at; quote_valid_until | one-quote-per-vendor edited in place (deliberately simple); awards immutable | **TRENDABLE-STATUS-MACHINE** (quote price history NOT kept — only winner award rows) |
| supplier_performance_snapshots | period_year+month (idx+tier), computed_at | monthly snapshot UQ vendor+period | **TRENDABLE-SNAPSHOT** (month grain) |
| shipments (+docs/containers/lots/landed_costs) | etd/atd/eta/ata/customs dates; lot_date (idx) | status machine; docs append | **TRENDABLE-STATUS-MACHINE** (lead-time dates present) |
| goods_receipt_notes | received_date (idx), accepted_at, handoff_at | status machine | **TRENDABLE-STATUS-MACHINE** |
| grn_items | created_at + updated_at present | quantity_accepted mutated on accept | **MIXED** — received fixed at finalize |
| attendances | date (idx; emp+date UQ) | recomputed in place (DTR rerun overwrites) | **OVERWRITE-per-day** — intra-day history = attendance_punches; corrections = audit_logs |
| attendance_punches | punched_at (emp+punched idx) | append-only | **TRENDABLE-LEDGER** |
| leave_requests | start_date+end_date (idx), approved_at pair, cancelled_at | status machine + allocation jsons | **TRENDABLE-STATUS-MACHINE** |
| employee_leave_balances | year | used/remaining mutated | **OVERWRITE-per-year** |
| overtime_requests | date (emp+date idx), approved/cancelled_at | status machine | **TRENDABLE-STATUS-MACHINE** |
| payroll_periods | period_start+end (idx), payroll_date, finalized/disbursed/voided_at | status machine; void reverses JE (never unlocks) | **TRENDABLE-STATUS-MACHINE** (cutoff grain) |
| payrolls | computed_at; payslip_emailed_at | immutable per period (recompute reverses loans first) | **TRENDABLE-LEDGER** (cutoff grain) |
| payroll_deduction_details | **NO timestamps** | rewritten on recompute | **MIXED** — join payrolls |
| payroll_adjustments / anomaly_flags / cycle_claims | applied_at / created_at / created_at | append-only | **TRENDABLE-LEDGER** |
| thirteenth_month_accruals | year (idx); paid_date | accrued mutated → is_paid at finalize | **MIXED** — accrual trail via payrolls |
| employee_loans | start/end_date | total_paid/balance/periods_remaining mutated | **MIXED** — repayment history = loan_payments |
| loan_payments | payment_date (loan+date idx) | append-only (deduction UQ) | **TRENDABLE-LEDGER** |
| employees | date_hired (idx), date_regularized | current-row mutated (dept/position/salary/status) | **OVERWRITE-current** — history = employment_history + employee_salary_history + audit_logs |
| employment_history / employee_salary_history | effective_date (idx) | append-only | **TRENDABLE-LEDGER** |
| clearances | separation_date (idx), finalized_at | status machine + final_pay_breakdown snapshot json | **TRENDABLE-STATUS-MACHINE** |
| machines / molds | running_hours_updated_at; commissioned/decommissioned/last_maintenance_at | status/counters mutated | **OVERWRITE-current** — history = mold_history + machine_downtimes + condition_readings + MWOs |
| mold_history | event_date (mold+date idx) | append-only | **TRENDABLE-LEDGER** |
| machine_condition_readings | recorded_at (machine+metric+recorded idx) | append-only telemetry | **TRENDABLE-LEDGER** |
| maintenance_work_orders | started_at (unindexed), completed_at (idx) | status machine | **TRENDABLE-STATUS-MACHINE** (MTTR needs started_at index, §4) |
| maintenance_logs / spare_part_usage / part_requests | created_at; handed_over_at | append-only | **TRENDABLE-LEDGER** |
| maintenance_schedules / windows | last_performed_at, next_due_at (idx); planned start/end | next_due mutated | **OVERWRITE-next-due** — completion history = MWOs |
| mrp_runs | run_at (idx), started/heartbeat_at | counters per run; orders only in summary json | **TRENDABLE-LEDGER** (run grain; order detail via plans) |
| mrp_plans | generated_at | versioned (active/superseded) | **TRENDABLE-LEDGER** (version grain) |
| bill_of_materials (+items) | costed_at | versioned (active partial UQ) | **TRENDABLE-LEDGER** (version grain; current cost = active row) |
| demand_forecasts | forecast_year+month (idx) | forecast row mutated with actuals later | **MIXED** — only actual_quantity IS NOT NULL comparable |
| budgets (+line_items) | fiscal_year; submitted/approved_at | total_spent/committed + actual_total/variance synced | **MIXED** — monthly plan cols (jan..dec) are the trend; actuals resynced |
| budget_transfers | approved/rejected_at | append-only | **TRENDABLE-LEDGER** |
| fiscal_years / accounting_periods | start/end_date; closed_at | status machine | **TRENDABLE-STATUS-MACHINE** |
| assets | acquisition_date (idx), disposed_date, insurance_expiry | accumulated_depreciation mutated | **MIXED** — depreciation history = asset_depreciations (monthly UQ) |
| asset_depreciations | period_year+month (idx) | monthly rows | **TRENDABLE-LEDGER** |
| customer_complaints (+8D) | received_date (idx); resolved/closed_at; SLA due trio (idx) | status machine | **TRENDABLE-STATUS-MACHINE** (SLA-ready) |
| product_price_agreements | effective_from (lookup idx), effective_to (idx) | effective-dated versions | **TRENDABLE-LEDGER** (validity grain) |
| return_requests (+items/+receipts) / return_cases (+lines/events) | return_date (**unindexed**); stage timestamps (**unindexed**); received_at (in request+received idx); status+created (cases idx) | status machines | **TRENDABLE-STATUS-MACHINE** (needs indexes, §4; F12) |
| material_issue_slips (+items) / stock_adjustments / stock_count_* / transfer_orders | issued_date (idx); created_at | issued/approved immutable; adjustments keep movement link | **TRENDABLE-LEDGER** (counts need status index, §4) |
| material_reservations / sales_order_stock_allocations | reserved_at; created_at | qty consumed/released mutated | **MIXED** |
| production_schedules | scheduled_start/end (machine+start idx; gist overlap) | pending→confirmed→executed/superseded | **TRENDABLE-STATUS-MACHINE** (utilization-ready) |
| calibration_records | last/next_calibration_date (idx) | status mutated | **OVERWRITE-current** (due/overdue computed) |
| ppap_submissions | submission_date; reviewed/approved/expires_at | status machine | **TRENDABLE-STATUS-MACHINE** |
| kpi_snapshots | period_year+month (UQ); computed_at (unindexed) | monthly snapshot, 2nd-of-month compute | **TRENDABLE-SNAPSHOT** (month grain ONLY — daily trends must query source tables) |
| bank_file_records / disbursement_proofs / de_minimis_benefits | generated_at; disbursement_date; period year+month | append-only per period | **TRENDABLE-LEDGER** |
| action_center_tasks (+events) | due_at; created_at | state machine | **TRENDABLE-STATUS-MACHINE** |
| government_contribution_tables | effective_date (agency+date UQ) | versioned by date | **TRENDABLE-LEDGER** (effective grain; payroll_date selects version) |
| login_history / documents / event_outbox | created_at; available_at (idx) | append-only | **TRENDABLE-LEDGER** |
| items / products / warehouses / locations-current / shifts / settings / masters | updated_at only | mutated in place | **OVERWRITE-current** (trend via movements/transactions that reference them) |
| users | last_activity, locked_until | mutated | **OVERWRITE-current** |

**Counts (80 rows above; multi-table rows counted once):** TRENDABLE-LEDGER 32 · TRENDABLE-STATUS-MACHINE 22 ·
TRENDABLE-SNAPSHOT 2 · MIXED 15 · OVERWRITE-current/per-day/per-year 9.
Forecastability rule of thumb: anything needing a date on a *-items line table, a status
history, or a daily grain below KPI month must join the parent/ledger — never the header alone.

---

## 4. Missing indexes (dashboard filter/aggregate columns with NO index)

Source: `pg_indexes` vs `information_schema` FKs on live `ogami` (403 FK cols lack a
dedicated index; audit `→ users.id` / portal-user FKs excluded below as low-value except where
portal dashboards need them). "Covers" = composite whose leftmost col is this one (usable);
second-position-only composites do NOT count. Each row = `table.column → target | why it matters`.

### 4a. HIGH — child→parent joins / fact filters (will hurt at scale)

| # | table.column → target | why |
|---|---|---|
| 1 | grn_items.goods_receipt_note_id → goods_receipt_notes.id | line→header join; only item_id/PO-item indexed |
| 2 | wo_operations.work_order_id → work_orders.id | THE operation→WO join; table has NO non-PK index at all |
| 3 | production_logs.wo_operation_id → wo_operations.id | OEE/downtime event joins; zero non-PK indexes |
| 4 | production_logs.operator_id → employees.id | operator productivity; zero indexes |
| 5 | wo_operations.status | operation-status boards / in-progress WIP |
| 6 | wo_operations.machine_id / .mold_id / .operator_id / .routing_operation_id | machine & operator load, mold usage |
| 7 | work_orders.product_id → products.id | product mix / Pareto by product |
| 8 | work_orders.mold_id → molds.id | mold shot/load dashboards (machine_id IS indexed, mold is not) |
| 9 | work_orders.parent_wo_id → work_orders.id | subassembly tree rollups |
| 10 | return_requests.status / .type / .return_date | returns dashboard primary filters — NONE indexed (F12) |
| 11 | return_requests.customer_id / .vendor_id / .sales_order_id / .purchase_order_id | returns by counterparty/order |
| 12 | return_requests.invoice_id / .bill_id / .ncr_id / .inspection_id / .replacement_wo_id / .credit_note_id / .stock_movement_id | every returns→finance/QC join |
| 13 | return_request_items.item_id / .product_id / .ncr_id | defect/returns Pareto |
| 14 | return_request_items.source_sales_order_item_id / .source_delivery_item_id / .source_po_item_id / .source_grn_item_id / .source_invoice_item_id / .source_bill_item_id | provenance joins (all six) |
| 15 | return_request_items.quarantine_location_id / .quarantine_movement_id / .quarantine_release_movement_id / .stock_movement_id | quarantine stock reconciliation |
| 16 | return_cases.credit_note_id / .replacement_sales_order_id / .replacement_purchase_order_id / .replacement_delivery_id / .resolution_goods_receipt_note_id | case→resolution joins |
| 17 | invoices.sales_order_id → sales_orders.id | SO→invoice AR join |
| 18 | invoices.delivery_id → deliveries.id | delivery→invoice join |
| 19 | deliveries.invoice_id → invoices.id | reverse invoice lookup |
| 20 | invoice_items.source_delivery_item_id → delivery_items.id | invoice↔delivery line trace |
| 21 | official_receipts.invoice_id → invoices.id | OR→invoice (collection IS indexed) |
| 22 | deliveries.vehicle_id / .driver_id → vehicles/users | fleet & driver utilization |
| 23 | deliveries.delivered_at / .confirmed_at / .received_at | OTD / lead-time aggregations (only scheduled_date indexed) |
| 24 | delivery_items.inspection_id → inspections.id | delivery↔QC join |
| 25 | purchase_requests.date | PR aging / intake trends (status+dept indexed, date not) |
| 26 | purchase_orders.date | PO cycle-time trends (only expected_delivery_date indexed) |
| 27 | stock_movements.from_location_id / .to_location_id | location-level flow / warehouse heatmaps |
| 28 | stock_movements.created_at (single-col) | global date-range scans (only item+created composites exist) |
| 29 | transfer_orders.status / .from_location_id / .to_location_id / .item_id | transfer boards (only transfer_number indexed) |
| 30 | stock_count_sessions.status / .warehouse_id / .zone_id | count operations (only session_number indexed) |
| 31 | inspections.item_id / .item_quality_plan_id / .wo_operation_id | incoming/in-process QC by item/plan/operation |
| 32 | inspections.inspection_spec_id / .inspection_spec_revision_id | spec coverage / revision adoption |
| 33 | inspections.started_at | inspection lead time (only completed_at indexed) |
| 34 | non_conformance_reports.replacement_work_order_id / .rework_work_order_id | NCR→recovery WO joins |
| 35 | customer_complaints.sales_order_id / .ncr_id / .replacement_work_order_id | complaint→order/NCR/recovery joins |
| 36 | supplier_quotes.status | quote pipeline (only rfq+vendor UQ) |
| 37 | supplier_quote_items.request_for_quote_item_id / .response_status | line-level comparison boards |
| 38 | rfq_awards.request_for_quote_id / .vendor_id / .supplier_quote_id | award→quote/vendor joins (only line UQ) |
| 39 | budgets.fiscal_year_id / .department_id / .status | THE budget filters — table has ONLY pkey |
| 40 | budget_transfers.from_line_item_id / .to_line_item_id | transfer flow (only status indexed) |
| 41 | credit_note_lines.credit_note_id / .account_id | table has ONLY pkey |
| 42 | credit_notes.invoice_id / .bill_id / .return_request_id / .status / .date | CN application + aging (only cust/vendor/type+status) |
| 43 | supplier_invoices.vendor_id / .purchase_order_id | payables by vendor/PO (only vendor+number UQ) |
| 44 | work_orders.actual_start / .actual_end | WO lead-time (only planned_start indexed) |
| 45 | maintenance_work_orders.started_at | MTTR (only completed_at indexed) |
| 46 | machine_downtimes.work_order_id / .maintenance_order_id | downtime↔WO↔MWO joins (only machine+start) |
| 47 | machines.current_work_order_id / .current_rework_ncr_id; molds.current_work_order_id / .current_rework_ncr_id | live load boards |
| 48 | demand_forecasts.product_id / .customer_id (single-col) | forecast-vs-actual by product/customer (only period idx + scope UQ) |
| 49 | mrp_plans.mrp_run_id → mrp_runs.id | run→plan drill-down (F6) |
| 50 | leave_requests.leave_type_id → leave_types.id | leave mix by type (emp+status indexed) |
| 51 | attendances.shift_id → shifts.id | attendance by shift |
| 52 | sales_order_items.price_agreement_id | price-source analytics |
| 53 | shipment_lots.product_id → products.id | lot traceability by product |
| 54 | containers.shipment_id → shipments.id | shipment→container joins |
| 55 | ppap_submissions.product_id / .purchase_order_id / .status(single) | PPAP boards (only vendor+item+status composite) |
| 56 | goods_receipt_notes.qc_inspection_id / .journal_entry_id / .supplier_shipment_id | GRN↔QC/GL/portal joins |
| 57 | bills.journal_entry_id / .department_id / .supplier_invoice_id | bill↔GL/dept/portal joins |
| 58 | bill_payments.cash_account_id / .journal_entry_id / .status | cash-flow + void analytics |
| 59 | collections.cash_account_id / .journal_entry_id | cash position by account |
| 60 | payroll_periods.journal_entry_id | period↔GL join |
| 61 | payroll_adjustments.original_payroll_id / .applied_to_payroll_id | adjustment lineage |
| 62 | payroll_anomaly_flags.payroll_id / .employee_id | anomaly drill-down (only period+resolved) |
| 63 | delivery_stock_reservations.delivery_id / .reservation_batch_id / .stock_movement_id | reservation reconciliation |
| 64 | sales_order_stock_allocations.inspection_id / .item_id / .status | allocation boards (only request_key + source UQ) |
| 65 | material_review_records.ncr_id / .inspection_id / .hold_movement_id / .release_movement_id / .quarantine_location_id / .source_location_id / .release_location_id | MRB dashboards |
| 66 | maintenance_part_requests.item_id / .location_id / .work_log_id | spares consumption joins |
| 67 | spare_part_usage.stock_movement_id | spares↔ledger join |
| 68 | maintenance_work_orders.schedule_id | schedule compliance |
| 69 | stock_adjustments.stock_movement_id | adjustment↔ledger join |
| 70 | material_issue_slips.issued_by / .created_by | issuer analytics (WO+date+status indexed) |
| 71 | material_issue_slip_items.location_id | issue by location |
| 72 | material_reservations.location_id | reservation by location |
| 73 | loan_payments.journal_entry_id | loan↔GL join |
| 74 | de_minimis_benefits.payroll_id | benefit↔payslip join |
| 75 | thirteenth_month_accruals.payroll_id | accrual↔payout join |
| 76 | invoice_payment_submissions.invoice_id (single) / .submitted_by | portal payment matching (only pending-partial + UQs) |
| 77 | portal_shipping_documents.bill_id / .supplier_invoice_id / .supplier_shipment_id | portal doc joins |
| 78 | supplier_shipments.portal_user_id / .return_request_id | portal shipment activity |
| 79 | warehouse_locations.current_item_id | bin-content lookups |
| 80 | stock_count_items.status | count progress (only session+loc+item UQ) |
| 81 | ncr_templates.product_id | template coverage |
| 82 | delivery_attempt_revisions.delivery_attempt_outcome_id / .return_request_id; delivery_cost_handoffs.delivery_attempt_outcome_id / .journal_entry_id | attempt/cost joins |
| 83 | action_center_task_events.task_id / .acted_by | task activity feeds |
| 84 | kpi_snapshots.computed_at | recompute auditing (only def+period UQ) |
| 85 | work_order_outputs.rework_ncr_id / .rework_source_output_id / .work_order_crew_id | rework + crew productivity joins |
| 86 | work_order_materials: OK (WO+item indexed) — listed for completeness, no gap |
| 87 | request_for_quote_items.item_id | RFQ demand by item |

**Count: 87 rows with a gap (row 86 = explicit no-gap) → 86 missing-index gaps** across
~45 tables, plus a standing class of ~300 low-value audit FKs (`created_by/approved_by/
reviewed_by → users.id`, portal-user FKs) intentionally omitted — add per-query only if a
dashboard actually groups by actor.

Migration check method for any row: `grep -rln '<table>' api/database/migrations | sort | tail -1`
then confirm absence of `->index()` on that column (DB `pg_indexes` above IS the applied-migration
truth — equivalent and faster).

### 4b. Unindexed status/date/code columns (no FK involved)

Covered inline above (§4a rows 10, 23, 25, 26, 28, 29, 30, 33, 44, 45) plus:
`employee_loans` write-off JE FKs, `invoices`/`bills` actor FKs (audit class),
`shifts` (master, tiny), `document_sequences` (counters — never dashboard-filtered).

---

## 5. Discrepancies vs docs + gaps/blockers

### 5.1 `docs/SCHEMA.md` — stale in at least 16 places (code wins)

| # | SCHEMA.md says | code reality |
|---|---|---|
| D1 | "~88 tables across 15 modules" (:6) | **261 tables**, 22 module dirs |
| D2 | "Migrations 0001–0169; 0132–0149 reserved for Sprint 8+" (:8-12) | **768 files**: `0001_`–`0499_` + `2026_*` timestamp files through `2026_10_10_*`; reservation note obsolete |
| D3 | `sales_orders.status` = 9 values (:389), no stage timestamps | 11 statuses (+awaiting_confirmation, delivery_negotiation); 8 indexed stage timestamps; +mrp_plan_id/incoterm/return_case_id/submission_source/portal idempotency |
| D4 | `customer_complaints`: `date_received`, severity critical/major/minor, `replacement_wo_id`, `invoice_id` FK (:394-395) | `received_date`; severity = shared NcrSeverity low/medium/high/critical (`CRM/Models/CustomerComplaint.php:14`); `replacement_work_order_id`; **no `invoice_id` column at all**; +ncr_id, SLA trio, handoff cols |
| D5 | `complaint_8d_reports`: d1_team json, completed_at (:397-398) | d1_team text; finalized_by/at |
| D6 | NCR `source`: incoming/in_process/outgoing/customer (:445) | `NcrSource` = **inspection_fail/customer_complaint**; stage lives on the inspection, not the NCR |
| D7 | `payroll_periods`: 4 statuses, 9 cols (:151-152) | 7 statuses (+computed/disbursed/voided); ~40 cols (scopes, disbursement/void, GL+bank handoffs, 13th-month run, correction) |
| D8 | `employee_loans`: loan_type 2 values, 5 statuses (:176-177) | +sss_loan/pagibig_loan; +write_off_pending/written_off; +write-off + 2 JE cols |
| D9 | `bills`: status unpaid/partial/paid (:201) | +draft/cancelled; +3-way snapshot/override, landed-cost, supplier-invoice, provenance, exception cols |
| D10 | `invoices`: status incl `unpaid` (:210) | **no `unpaid`** (draft/finalized/partial/paid/cancelled); +dunning, VAT-class, prebill, lifecycle, cancel cols |
| D11 | `collections`: 6 cols, no cash account (:216) | +cash_account_id, idempotency_key, manual_reason |
| D12 | `products` filed under MRP (:360) | model is `CRM/Models/Product.php` — CRM-owned; MRP/BOM references it |
| D13 | Missing whole sections | Budgeting (5 tables), Forecasting, B2B portal (8), Returns depth (10: requests/items/receipts/cases/allocations), Quality depth (PPAP, calibration, item plans, spec revisions, piece readings, escalation/recurrence/effectiveness), Maintenance depth (part requests, windows, readings), SupplyChain depth (containers, lots, attempt outcomes, stock reservations, schedules, cost handoffs), Payroll depth (claims, anomalies, disbursement proofs, de-minimis), Dashboard/KPI (6), HR depth (recruitment, trainings, salary history) |
| D14 | `work_orders`: 6 statuses, no SO-item/parent/rework/BOM/cost/workflow cols (:334-336) | +cancelled; +sales_order_item_id (unenforced, F1), parent_wo_id, parent_ncr_id, rework_source_output_id, bom+cost snapshot, qc_workflow, production_purpose, stock idempotency |
| D15 | `machines.status` 4 values, no asset/hours/rework (:369) | +offline; +asset_id (UQ 1:1), running_hours_total, current_rework_ncr_id |
| D16 | `uoms.deleted_at`? minor — `warehouse_locations` gains capacity/current_*/blocked cols; `items` gains safety_stock_locked/recomputed_at/abc_class — all absent from SCHEMA text |

### 5.2 `CLAUDE.md` — 3 stale counts + 1 misleading module note

- **C1.** "43 scheduled commands as of 2026-08-25" → **63 `Schedule::command()` entries** in `api/routes/console.php` today (retry/sweep/escalation growth).
- **C2.** "Highest [migration] as of 2026-09-06 = 0481" → actual max `0499_*` + `2026_10_10_*` timestamp files. (Naming-collision + timestamp-dependency rules in CLAUDE.md remain valid.)
- **C3.** Module 17 "Budgeting (budgets, budget line items, **revisions**, transfers, fiscal year)" → **no `budget_revisions` table exists** (verified: budgets, budget_line_items, budget_transfers, budget_actuals_sync_runs, fiscal_years). "Revisions" most plausibly means `inspection_spec_revisions` or transfer-driven edits — dashboard scope should NOT plan a revisions fact table.
- **C4.** "1900 tests / ~28 min" — not re-verified in this read-only pass (no suite run).

### 5.3 Gaps / blockers for dashboard work (actionable)

- **G1 — Line-item tables mostly lack dates.** `sales_order_items`, `purchase_order_items`, `invoice_items`, `bill_items`, `work_order_defects`, `payroll_deduction_details` carry NO timestamps — every time-series on lines must join the parent header date. (`delivery_items`, `grn_items`, `supplier_quote_items`, `bom_items` DO have timestamps.)
- **G2 — Status history = `audit_logs` or nothing.** Single current `status` everywhere; 136 models emit audit rows (SO/WO/payroll/NCR included) but StockLevel/StockMovement do not (F11). Dwell-time dashboards need `audit_logs.model_type+model_id+created_at` (indexed composite — good).
- **G3 — KPI grain is monthly.** `kpi_snapshots` unique on (definition, year, month), computed 2nd @03:00. Any daily/weekly trend, OEE, OTD, or funnel widget queries source tables directly; do NOT stretch snapshots.
- **G4 — §4 indexes.** Cheapest wins before dashboards ship: rows 1-6 (GRN/WO-log joins), 10-15 (returns), 39-41 (budgets/credit lines), 25-26 (PR/PO dates), 23/44/45 (lead-time timestamps).
- **G5 — Handoff statuses are first-class dimensions.** `*_handoff_status` (invoice/CoC/incoming-QC/GL/production-receipt) with `manual_required` backlogs must appear on every chain-funnel widget, or broken links (F2-F4) read as zero volume.
- **G6 — PII discipline.** `employees`/`customers`/`vendors` carry encrypted identifiers + masked resources; HR widgets aggregate (headcount/turnover/tenure), never list identifiers.
- **G7 — HashIDs at the edge, ints inside.** All dashboard drill links use `hash_id` strings; DB joins stay bigint.
- **G8 — Forecast comparability gate.** Filter `demand_forecasts.actual_quantity IS NOT NULL` (F7); supplier scorecards prefer `supplier_performance_snapshots` (monthly) over live recomputation.
