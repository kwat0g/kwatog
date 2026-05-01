# OGAMI ERP — Database Schema Reference

> Every table, every column, every type. Tasks reference this document.
> **Conventions:** PKs = `id` bigint auto-increment (exposed as HashIDs). Money = `decimal(15,2)` NEVER float. Timestamps = `created_at, updated_at`. FK columns = `{table_singular}_id`. Soft deletes where noted.

## Total: ~85 tables across 15 modules

---

## AUTH & SYSTEM (12 tables)

### roles
id, name (string 50), slug (string 50 unique), description (text nullable), created_at, updated_at

### permissions
id, name (string 100), slug (string 100 unique), module (string 50), description (text nullable), created_at, updated_at

### role_permissions
role_id (FK roles), permission_id (FK permissions), PRIMARY KEY (role_id, permission_id)

### users
id, name (string 100), email (string unique), password (string), role_id (FK roles), employee_id (FK employees nullable), is_active (bool default true), must_change_password (bool default false), last_activity (timestamp nullable), password_changed_at (timestamp nullable), failed_login_attempts (int default 0), locked_until (timestamp nullable), theme_mode (string 10 default 'system'), sidebar_collapsed (bool default false), remember_token, created_at, updated_at, deleted_at

### password_history
id, user_id (FK users), password_hash (string), created_at

### sessions (Laravel built-in database sessions)
id (string), user_id (FK users nullable), ip_address (string 45 nullable), user_agent (text nullable), payload (text), last_activity (int)

### audit_logs
id, user_id (FK users nullable), action (string 20: created/updated/deleted), model_type (string 100), model_id (bigint nullable), old_values (json nullable), new_values (json nullable), ip_address (string 45 nullable), user_agent (text nullable), created_at

### document_sequences
id, document_type (string 30), prefix (string 10), year (int), month (int), last_number (int default 0), UNIQUE (document_type, year, month)

### workflow_definitions
id, workflow_type (string 50 unique), name (string 100), steps (json), amount_threshold (decimal 15,2 nullable), created_at, updated_at

### approval_records
id, approvable_type (string 100), approvable_id (bigint), step_order (int), role_slug (string 50), approver_id (FK users nullable), action (string 20: pending/approved/rejected), remarks (text nullable), acted_at (timestamp nullable), created_at

### notifications (Laravel built-in)
id (uuid), type (string), notifiable_type (string), notifiable_id (bigint), data (json), read_at (timestamp nullable), created_at, updated_at

### notification_preferences
id, user_id (FK users), notification_type (string 100), channel (string 20: in_app/email), enabled (bool default true), UNIQUE (user_id, notification_type, channel)

### settings
id, key (string 100 unique), value (json), group (string 50), created_at, updated_at

---

## HR (5 tables)

### departments
id, name (string 100), code (string 20 unique), parent_id (FK departments nullable), head_employee_id (FK employees nullable), is_active (bool default true), created_at, updated_at

### positions
id, title (string 100), department_id (FK departments), salary_grade (string 20 nullable), created_at, updated_at

### employees
id, employee_no (string 20 unique), first_name (string 100), middle_name (string 100 nullable), last_name (string 100), suffix (string 20 nullable), birth_date (date), gender (string 10), civil_status (string 20), nationality (string 50 default 'Filipino'), photo_path (string nullable), street_address (string 200 nullable), barangay (string 100 nullable), city (string 100 nullable), province (string 100 nullable), zip_code (string 10 nullable), mobile_number (string 20 nullable), email (string nullable), emergency_contact_name (string 100 nullable), emergency_contact_relation (string 50 nullable), emergency_contact_phone (string 20 nullable), sss_no (text nullable ENCRYPTED), philhealth_no (text nullable ENCRYPTED), pagibig_no (text nullable ENCRYPTED), tin (text nullable ENCRYPTED), department_id (FK departments), position_id (FK positions), employment_type (string 20: regular/probationary/contractual/project_based), pay_type (string 10: monthly/daily), date_hired (date), date_regularized (date nullable), basic_monthly_salary (decimal 15,2 nullable), daily_rate (decimal 15,2 nullable), bank_name (string 100 nullable), bank_account_no (text nullable ENCRYPTED), status (string 20: active/on_leave/suspended/resigned/terminated/retired), created_at, updated_at, deleted_at

### employee_documents
id, employee_id (FK employees), document_type (string 50), file_name (string 200), file_path (string 500), uploaded_at (timestamp), created_at

### employment_history
id, employee_id (FK employees), change_type (string 30: hired/promoted/transferred/salary_adjusted/regularized/separated), from_value (json nullable), to_value (json), effective_date (date), remarks (text nullable), approved_by (FK users nullable), created_at

### employee_property
id, employee_id (FK employees), item_name (string 200), description (text nullable), quantity (int default 1), date_issued (date), date_returned (date nullable), status (string 20: issued/returned/lost), created_at, updated_at

### clearances
id, employee_id (FK employees), separation_date (date), separation_reason (string 50: resigned/terminated/retired/end_of_contract), clearance_items (json), final_pay_computed (bool default false), final_pay_amount (decimal 15,2 nullable), final_pay_breakdown (json nullable), status (string 20: pending/in_progress/completed), created_at, updated_at

---

## ATTENDANCE (5 tables)

### shifts
id, name (string 50), start_time (time), end_time (time), break_minutes (int), is_night_shift (bool default false), is_extended (bool default false), auto_ot_hours (decimal 3,1 nullable), created_at, updated_at

### employee_shift_assignments
id, employee_id (FK employees), shift_id (FK shifts), effective_date (date), end_date (date nullable), created_at

### attendances
id, employee_id (FK employees), date (date), shift_id (FK shifts), time_in (timestamp nullable), time_out (timestamp nullable), regular_hours (decimal 5,2 default 0), overtime_hours (decimal 5,2 default 0), night_diff_hours (decimal 5,2 default 0), tardiness_minutes (int default 0), undertime_minutes (int default 0), holiday_type (string 30 nullable), is_rest_day (bool default false), day_type_rate (decimal 5,2 default 1.00), status (string 20), is_manual_entry (bool default false), remarks (text nullable), created_at, updated_at, INDEX (employee_id, date)

### overtime_requests
id, employee_id (FK employees), date (date), hours_requested (decimal 3,1), reason (text), status (string 20), created_at, updated_at

### holidays
id, name (string 100), date (date), type (string 30: regular/special_non_working), is_recurring (bool default false), created_at, updated_at

---

## LEAVE (3 tables)

### leave_types
id, name (string 100), code (string 10 unique), default_balance (decimal 5,1), is_paid (bool default true), requires_document (bool default false), is_convertible_on_separation (bool default false), is_convertible_year_end (bool default false), conversion_rate (decimal 3,2 default 1.00), is_active (bool default true), created_at, updated_at

### employee_leave_balances
id, employee_id (FK employees), leave_type_id (FK leave_types), year (int), total_credits (decimal 5,1), used (decimal 5,1 default 0), remaining (decimal 5,1), created_at, updated_at, UNIQUE (employee_id, leave_type_id, year)

### leave_requests
id, leave_request_no (string 20), employee_id (FK employees), leave_type_id (FK leave_types), start_date (date), end_date (date), days (decimal 3,1), reason (text nullable), document_path (string nullable), status (string 20: pending_dept/pending_hr/approved/rejected), rejection_reason (text nullable), created_at, updated_at

---

## PAYROLL (6 tables)

### payroll_periods
id, period_start (date), period_end (date), payroll_date (date), is_first_half (bool), status (string 20: draft/processing/approved/finalized), journal_entry_id (FK journal_entries nullable), created_by (FK users), created_at, updated_at

### payrolls
id, payroll_period_id (FK payroll_periods), employee_id (FK employees), pay_type (string 10), days_worked (decimal 4,1 nullable), basic_pay (decimal 15,2), overtime_pay (decimal 15,2 default 0), night_diff_pay (decimal 15,2 default 0), holiday_pay (decimal 15,2 default 0), gross_pay (decimal 15,2), sss_ee (decimal 15,2 default 0), sss_er (decimal 15,2 default 0), philhealth_ee (decimal 15,2 default 0), philhealth_er (decimal 15,2 default 0), pagibig_ee (decimal 15,2 default 0), pagibig_er (decimal 15,2 default 0), withholding_tax (decimal 15,2 default 0), loan_deductions (decimal 15,2 default 0), other_deductions (decimal 15,2 default 0), adjustment_amount (decimal 15,2 default 0), total_deductions (decimal 15,2), net_pay (decimal 15,2), created_at, updated_at

### payroll_deduction_details
id, payroll_id (FK payrolls), deduction_type (string 30), description (string 200 nullable), amount (decimal 15,2), reference_id (bigint nullable)

### payroll_adjustments
id, payroll_period_id (FK payroll_periods), employee_id (FK employees), original_payroll_id (FK payrolls), type (string 20: underpayment/overpayment), amount (decimal 15,2), reason (text), approved_by (FK users nullable), status (string 20), created_at, updated_at

### government_contribution_tables
id, agency (string 20: sss/philhealth/pagibig/bir), bracket_min (decimal 15,2), bracket_max (decimal 15,2), ee_amount (decimal 15,2), er_amount (decimal 15,2), effective_date (date), is_active (bool default true), created_at, updated_at, INDEX (agency, is_active)

### thirteenth_month_accruals
id, employee_id (FK employees), year (int), total_basic_earned (decimal 15,2), accrued_amount (decimal 15,2), is_paid (bool default false), paid_date (date nullable), payroll_id (FK payrolls nullable), created_at, updated_at, UNIQUE (employee_id, year)

### bank_file_records
id, payroll_period_id (FK payroll_periods), file_path (string), record_count (int), total_amount (decimal 15,2), generated_by (FK users), generated_at (timestamp), created_at

---

## LOANS (2 tables)

### employee_loans
id, loan_no (string 20), employee_id (FK employees), loan_type (string 20: company_loan/cash_advance), principal (decimal 15,2), monthly_amortization (decimal 15,2), total_paid (decimal 15,2 default 0), balance (decimal 15,2), start_date (date), end_date (date nullable), pay_periods_remaining (int), status (string 20: pending/active/paid/cancelled), is_final_pay_deduction (bool default false), created_at, updated_at

### loan_payments
id, loan_id (FK employee_loans), payroll_id (FK payrolls nullable), amount (decimal 15,2), payment_date (date), remarks (string nullable), created_at

---

## ACCOUNTING (8 tables — lean)

### accounts
id, code (string 20 unique), name (string 200), account_type (string 20: asset/liability/equity/revenue/expense), parent_id (FK accounts nullable), normal_balance (string 10: debit/credit), is_active (bool default true), description (text nullable), created_at, updated_at

### journal_entries
id, entry_number (string 20 unique), date (date), description (text), reference_type (string 30 nullable), reference_id (bigint nullable), total_debit (decimal 15,2), total_credit (decimal 15,2), status (string 20: draft/posted/reversed), reversed_by_entry_id (FK journal_entries nullable), created_by (FK users), posted_by (FK users nullable), posted_at (timestamp nullable), created_at, updated_at

### journal_entry_lines
id, journal_entry_id (FK journal_entries), account_id (FK accounts), debit (decimal 15,2 default 0), credit (decimal 15,2 default 0), description (string 200 nullable)

### vendors
id, name (string 200), contact_person (string 100 nullable), email (string nullable), phone (string 20 nullable), address (text nullable), tin (string 20 nullable), payment_terms_days (int default 30), is_active (bool default true), created_at, updated_at, deleted_at

### bills
id, bill_number (string 50), vendor_id (FK vendors), purchase_order_id (FK purchase_orders nullable), date (date), due_date (date), subtotal (decimal 15,2), vat_amount (decimal 15,2 default 0), total_amount (decimal 15,2), amount_paid (decimal 15,2 default 0), balance (decimal 15,2), status (string 20: unpaid/partial/paid), journal_entry_id (FK journal_entries nullable), created_at, updated_at

### bill_items
id, bill_id (FK bills), description (string 200), quantity (decimal 10,2), unit (string 20 nullable), unit_price (decimal 15,2), total (decimal 15,2)

### bill_payments
id, bill_id (FK bills), payment_date (date), amount (decimal 15,2), payment_method (string 30), reference_number (string 50 nullable), journal_entry_id (FK journal_entries nullable), created_at

### customers
id, name (string 200), contact_person (string 100 nullable), email (string nullable), phone (string 20 nullable), address (text nullable), tin (string 20 nullable), credit_limit (decimal 15,2 nullable), payment_terms_days (int default 30), is_active (bool default true), created_at, updated_at, deleted_at

### invoices
id, invoice_number (string 20 unique), customer_id (FK customers), sales_order_id (FK sales_orders nullable), delivery_id (FK deliveries nullable), date (date), due_date (date), subtotal (decimal 15,2), vat_amount (decimal 15,2 default 0), total_amount (decimal 15,2), amount_paid (decimal 15,2 default 0), balance (decimal 15,2), status (string 20: draft/finalized/unpaid/partial/paid), journal_entry_id (FK journal_entries nullable), created_at, updated_at

### invoice_items
id, invoice_id (FK invoices), product_id (FK products nullable), description (string 200), quantity (decimal 10,2), unit (string 20 nullable), unit_price (decimal 15,2), total (decimal 15,2)

### collections
id, invoice_id (FK invoices), collection_date (date), amount (decimal 15,2), payment_method (string 30), reference_number (string 50 nullable), journal_entry_id (FK journal_entries nullable), created_at

---

## INVENTORY (10 tables)

### item_categories
id, name (string 100), parent_id (FK item_categories nullable), created_at, updated_at

### items
id, code (string 30 unique), name (string 200), category_id (FK item_categories), item_type (string 20: raw_material/finished_good/packaging/spare_part), unit_of_measure (string 20), standard_cost (decimal 15,4 default 0), reorder_method (string 20: fixed_quantity/days_of_supply), reorder_point (decimal 15,3 default 0), safety_stock (decimal 15,3 default 0), lead_time_days (int default 0), is_critical (bool default false), is_active (bool default true), created_at, updated_at, deleted_at

### warehouses
id, name (string 100), code (string 20 unique), address (text nullable), created_at, updated_at

### warehouse_zones
id, warehouse_id (FK warehouses), name (string 50), code (string 10), zone_type (string 30), created_at

### warehouse_locations
id, zone_id (FK warehouse_zones), code (string 20 unique), rack (string 10 nullable), bin (string 10 nullable), created_at

### stock_levels
id, item_id (FK items), location_id (FK warehouse_locations), quantity (decimal 15,3), reserved_quantity (decimal 15,3 default 0), weighted_avg_cost (decimal 15,4), last_counted_at (timestamp nullable), updated_at, UNIQUE (item_id, location_id)

### stock_movements
id, item_id (FK items), from_location_id (FK warehouse_locations nullable), to_location_id (FK warehouse_locations nullable), movement_type (string 30), quantity (decimal 15,3), unit_cost (decimal 15,4), total_cost (decimal 15,2), reference_type (string 50 nullable), reference_id (bigint nullable), remarks (text nullable), created_by (FK users), created_at

### goods_receipt_notes
id, grn_number (string 20 unique), purchase_order_id (FK purchase_orders), vendor_id (FK vendors), received_date (date), received_by (FK users), items (json), status (string 20: pending_qc/accepted/rejected), qc_inspection_id (FK inspections nullable), created_at, updated_at

### material_issue_slips
id, slip_number (string 20), work_order_id (FK work_orders), issued_date (date), issued_by (FK users), items (json), status (string 20), created_at, updated_at

### material_reservations
id, item_id (FK items), work_order_id (FK work_orders), quantity (decimal 15,3), status (string 20: reserved/issued/released), reserved_at (timestamp), released_at (timestamp nullable)

---

## PURCHASING (5 tables)

### purchase_requests
id, pr_number (string 20), requested_by (FK users), department_id (FK departments), date (date), reason (text), status (string 20: draft/pending/approved/rejected/converted), is_auto_generated (bool default false), created_at, updated_at

### purchase_request_items
id, purchase_request_id (FK purchase_requests), item_id (FK items nullable), description (string 200), quantity (decimal 10,2), unit (string 20), estimated_unit_price (decimal 15,2 nullable)

### purchase_orders
id, po_number (string 20 unique), vendor_id (FK vendors), purchase_request_id (FK purchase_requests nullable), date (date), expected_delivery_date (date nullable), subtotal (decimal 15,2), vat_amount (decimal 15,2 default 0), total_amount (decimal 15,2), status (string 20: draft/approved/sent/partially_received/received/cancelled), approved_by (FK users nullable), approved_at (timestamp nullable), sent_to_supplier_at (timestamp nullable), remarks (text nullable), created_at, updated_at

### purchase_order_items
id, purchase_order_id (FK purchase_orders), item_id (FK items), description (string 200), quantity (decimal 10,2), unit (string 20), unit_price (decimal 15,2), total (decimal 15,2), quantity_received (decimal 10,2 default 0)

### approved_suppliers
id, item_id (FK items), vendor_id (FK vendors), is_preferred (bool default false), lead_time_days (int), last_price (decimal 15,2 nullable), created_at, updated_at, UNIQUE (item_id, vendor_id)

---

## SUPPLY CHAIN (5 tables)

### shipments
id, purchase_order_id (FK purchase_orders), shipment_type (string 20: import/export), origin_country (string 50 nullable), status (string 30), estimated_arrival (date nullable), actual_arrival (date nullable), carrier (string 100 nullable), tracking_number (string 100 nullable), created_at, updated_at

### shipment_documents
id, shipment_id (FK shipments), document_type (string 50), file_path (string nullable), file_name (string nullable), status (string 20), received_at (timestamp nullable), created_at

### vehicles
id, name (string 100), type (string 30: truck/van), plate_number (string 20), is_active (bool default true), created_at, updated_at

### deliveries
id, delivery_note_number (string 20), sales_order_id (FK sales_orders nullable), customer_id (FK customers), vehicle_id (FK vehicles nullable), driver_name (string 100 nullable), scheduled_date (date), actual_delivery_date (date nullable), status (string 30: scheduled/loading/in_transit/delivered/confirmed), delivery_receipt_path (string nullable), confirmed_by (string nullable), confirmed_at (timestamp nullable), created_at, updated_at

### delivery_items
id, delivery_id (FK deliveries), product_id (FK products), quantity (decimal 10,2), work_order_id (FK work_orders nullable)

---

## PRODUCTION (7 tables)

### work_orders
id, wo_number (string 20 unique), product_id (FK products), sales_order_id (FK sales_orders nullable), machine_id (FK machines nullable), mold_id (FK molds nullable), quantity_target (decimal 10,0), quantity_produced (decimal 10,0 default 0), quantity_good (decimal 10,0 default 0), quantity_rejected (decimal 10,0 default 0), scrap_rate (decimal 5,2 default 0), planned_start (datetime), planned_end (datetime), actual_start (datetime nullable), actual_end (datetime nullable), status (string 20: planned/confirmed/in_progress/paused/completed/closed), pause_reason (string nullable), priority (int default 0), created_by (FK users), created_at, updated_at

### work_order_materials
id, work_order_id (FK work_orders), item_id (FK items), bom_quantity (decimal 15,3), actual_quantity_issued (decimal 15,3 default 0), variance (decimal 15,3 default 0)

### work_order_outputs
id, work_order_id (FK work_orders), recorded_by (FK users), recorded_at (timestamp), good_count (int), reject_count (int), shift (string 20 nullable), remarks (text nullable)

### work_order_defects
id, output_id (FK work_order_outputs), defect_type_id (FK defect_types), count (int)

### defect_types
id, code (string 10 unique), name (string 100), description (text nullable), is_active (bool default true)

### machine_downtimes
id, machine_id (FK machines), work_order_id (FK work_orders nullable), start_time (timestamp), end_time (timestamp nullable), duration_minutes (int nullable), category (string 30: breakdown/changeover/material_shortage/no_order/planned_maintenance), description (text nullable), maintenance_order_id (FK maintenance_work_orders nullable), created_at, updated_at

### production_schedules
id, work_order_id (FK work_orders), machine_id (FK machines), mold_id (FK molds), scheduled_start (datetime), scheduled_end (datetime), priority_order (int), is_confirmed (bool default false), confirmed_by (FK users nullable), confirmed_at (timestamp nullable), created_at, updated_at

---

## MRP (6 tables)

### products
id, part_number (string 30 unique), name (string 200), description (text nullable), unit_of_measure (string 20 default 'pcs'), standard_cost (decimal 15,2 default 0), is_active (bool default true), created_at, updated_at, deleted_at

### bill_of_materials
id, product_id (FK products unique), version (int default 1), is_active (bool default true), created_at, updated_at

### bom_items
id, bom_id (FK bill_of_materials), item_id (FK items), quantity_per_unit (decimal 10,4), unit (string 20), waste_factor (decimal 5,2 default 0)

### machines
id, machine_code (string 20 unique), name (string 100), tonnage (int nullable), machine_type (string 50 default 'injection_molder'), operators_required (decimal 3,1 default 1.0), available_hours_per_day (decimal 4,1 default 16.0), status (string 20: running/idle/maintenance/breakdown), current_work_order_id (FK work_orders nullable), created_at, updated_at, deleted_at

### molds
id, mold_code (string 20 unique), name (string 100), product_id (FK products), cavity_count (int), cycle_time_seconds (int), output_rate_per_hour (int), setup_time_minutes (int default 90), current_shot_count (int default 0), max_shots_before_maintenance (int), lifetime_total_shots (int default 0), lifetime_max_shots (int), status (string 20), location (string 50 nullable), asset_id (FK assets nullable), created_at, updated_at, deleted_at

### mold_machine_compatibility
mold_id (FK molds), machine_id (FK machines), PRIMARY KEY (mold_id, machine_id)

### mold_history
id, mold_id (FK molds), event_type (string 30), description (text nullable), cost (decimal 15,2 nullable), performed_by (string 100 nullable), event_date (date), shot_count_at_event (int), created_at

---

## CRM (6 tables)

### product_price_agreements
id, product_id (FK products), customer_id (FK customers), price (decimal 15,2), effective_from (date), effective_to (date), created_at, updated_at, INDEX (product_id, customer_id, effective_from)

### sales_orders
id, so_number (string 20 unique), customer_id (FK customers), date (date), subtotal (decimal 15,2), vat_amount (decimal 15,2), total_amount (decimal 15,2), status (string 20: confirmed/in_production/partially_delivered/delivered/invoiced/cancelled), created_by (FK users), created_at, updated_at

### sales_order_items
id, sales_order_id (FK sales_orders), product_id (FK products), quantity (decimal 10,2), unit_price (decimal 15,2), total (decimal 15,2), quantity_delivered (decimal 10,2 default 0), delivery_date (date)

### customer_complaints
id, complaint_number (string 20), customer_id (FK customers), sales_order_id (FK sales_orders nullable), invoice_id (FK invoices nullable), product_id (FK products nullable), date_received (date), severity (string 20: critical/major/minor), description (text), affected_quantity (int nullable), status (string 30), replacement_wo_id (FK work_orders nullable), credit_memo_id (FK invoices nullable), created_by (FK users), created_at, updated_at

### complaint_8d_reports
id, complaint_id (FK customer_complaints unique), d1_team (json), d2_problem_description (text), d3_containment_action (text nullable), d4_root_cause (text nullable), d5_corrective_action (text nullable), d6_verification (text nullable), d7_preventive_action (text nullable), d8_team_recognition (text nullable), completed_at (timestamp nullable), created_at, updated_at

---

## QUALITY (5 tables)

### inspection_specs
id, product_id (FK products unique), version (int default 1), created_at, updated_at

### inspection_spec_items
id, spec_id (FK inspection_specs), parameter_name (string 100), parameter_type (string 20: dimensional/visual/functional), unit (string 20 nullable), nominal_value (decimal 10,4 nullable), tolerance_min (decimal 10,4 nullable), tolerance_max (decimal 10,4 nullable), is_critical (bool default false), sort_order (int)

### inspections
id, inspection_number (string 20), stage (string 20: incoming/in_process/outgoing), inspected_entity_type (string 30), inspected_entity_id (bigint), product_id (FK products nullable), batch_quantity (int), sample_size (int), good_count (int default 0), reject_count (int default 0), result (string 10: pass/fail/pending), inspector_id (FK users), inspected_at (timestamp), remarks (text nullable), created_at, updated_at

### inspection_measurements
id, inspection_id (FK inspections), spec_item_id (FK inspection_spec_items), measured_value (decimal 10,4 nullable), result (string 10: pass/fail), remarks (string nullable)

### non_conformance_reports
id, ncr_number (string 20 unique), source (string 20: incoming/in_process/outgoing/customer), inspection_id (FK inspections nullable), complaint_id (FK customer_complaints nullable), product_id (FK products), severity (string 20), description (text), affected_quantity (int), disposition (string 20: scrap/rework/use_as_is/return_to_supplier), root_cause (text nullable), status (string 30), replacement_wo_id (FK work_orders nullable), created_at, updated_at

### ncr_actions
id, ncr_id (FK non_conformance_reports), action_type (string 30: corrective/preventive), description (text), responsible_person (string 100), due_date (date), completed_at (date nullable), status (string 20)

---

## MAINTENANCE (4 tables)

### maintenance_schedules
id, maintainable_type (string 50: machine/mold), maintainable_id (bigint), schedule_type (string 20 default 'preventive'), description (string 200), interval_type (string 20: hours/days/shots), interval_value (int), last_performed_at (timestamp nullable), next_due_at (timestamp nullable), is_active (bool default true), created_at, updated_at

### maintenance_work_orders
id, maintainable_type (string 50), maintainable_id (bigint), schedule_id (FK maintenance_schedules nullable), type (string 20: preventive/corrective), priority (string 20: critical/high/medium/low), description (text), assigned_to (FK employees nullable), status (string 20), started_at (timestamp nullable), completed_at (timestamp nullable), downtime_minutes (int default 0), cost (decimal 15,2 default 0), remarks (text nullable), created_at, updated_at

### maintenance_logs
id, work_order_id (FK maintenance_work_orders), description (text), logged_by (FK users), created_at

### spare_part_usage
id, work_order_id (FK maintenance_work_orders), item_id (FK items), quantity (decimal 10,2), unit_cost (decimal 15,2), total_cost (decimal 15,2), created_at

---

## ASSETS (2 tables)

### assets
id, asset_code (string 20 unique), name (string 200), description (text nullable), category (string 50: machine/mold/vehicle/equipment/furniture/other), department_id (FK departments nullable), acquisition_date (date), acquisition_cost (decimal 15,2), useful_life_years (int), salvage_value (decimal 15,2 default 0), accumulated_depreciation (decimal 15,2 default 0), status (string 20: active/under_maintenance/disposed), disposed_date (date nullable), disposal_amount (decimal 15,2 nullable), location (string 100 nullable), linked_type (string 30 nullable), linked_id (bigint nullable), qr_code_data (string nullable), created_at, updated_at, deleted_at

### asset_depreciations
id, asset_id (FK assets), period_year (int), period_month (int), depreciation_amount (decimal 15,2), accumulated_after (decimal 15,2), journal_entry_id (FK journal_entries nullable), created_at, UNIQUE (asset_id, period_year, period_month)

---

**Total: ~85 tables.** Every money field decimal(15,2). Every PK bigint. All timestamps. Sensitive fields encrypted. Soft deletes on master data. Indexes on FKs and frequently filtered columns.
