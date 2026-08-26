id: M022
domain: people
module: payslip-statutory-disbursement
tier: 2
roles: system_admin, hr_officer, finance_officer, production_manager, ppc_head, purchasing_officer, warehouse_staff, qc_inspector, maintenance_tech, impex_officer, department_head, employee, driver; statutory: system_admin, hr_officer, finance_officer
depends_on: payroll-period-processing, employee-master, documents-exports, journal-ledger
surface: L
status: 🔁 Needs Re-audit — P02-01 payroll JE created_by is NULL (real defect, fix lives in locked finance/journal-ledger JournalEntryService.php:139; policy decision required, options A/B/C in fix-log). F01+F02 now executed and VERIFIED green (52 tests/156 assertions). Deferred: F03 statutory artifact contract, F04 taxable-base policy, F05 completeness preflight, F06 export-run ledger/checksum/retention, F07 provider message-id idempotency, F08 versioned payslip artifacts, F09 frontend/e2e leg unverified.
last_session: 2026-08-26
