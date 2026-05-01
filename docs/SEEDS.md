# OGAMI ERP — Seed Data Reference

> Exact data for seeders. Tasks reference this document.

---

## 1. DEPARTMENTS (11)

| Code | Name |
|---|---|
| EXEC | Executive |
| HR | Human Resources |
| FIN | Finance & Accounting |
| PROD | Production |
| QC | Quality Control |
| WH | Warehouse & Logistics |
| PUR | Purchasing & Procurement |
| PPC | Production Planning |
| MAINT | Maintenance & Engineering |
| MOLD | Mold Department |
| IMPEX | Import/Export |
| ADMIN | Admin & General Affairs |

## 2. POSITIONS (~35)

EXEC: Chairman, President, Vice President
HR: HR Manager, Gen Admin Officer, HR Staff
FIN: Accounting Officer, Accounting Staff
PROD: Plant Manager, Production Manager, Production Head, Processing Head, Production Operator
QC: QC/QA Manager, QC/QA Head, QC Inspector, Management System Head
WH: Warehouse Head, Warehouse Staff, Driver
PUR: Purchasing Officer, Purchasing Staff
PPC: PPC Head, PPC Staff
MAINT: Maintenance Head, Maintenance Technician
MOLD: Mold Manager, Mold Technician
IMPEX: ImpEx Officer, ImpEx Staff
ADMIN: Admin Staff

## 3. SHIFTS (4)

| Name | Start | End | Break (min) | Night | Extended | Auto OT |
|---|---|---|---|---|---|---|
| Day Shift | 06:00 | 14:00 | 30 | false | false | null |
| Extended Day | 06:00 | 18:00 | 30 | false | true | 4.0 |
| Night Shift | 18:00 | 06:00 | 30 | true | false | null |
| Office Hours | 08:00 | 17:00 | 60 | false | false | null |

## 4. HOLIDAYS (2026 Philippines, ~21)

| Date | Name | Type |
|---|---|---|
| 2026-01-01 | New Year's Day | regular |
| 2026-02-17 | Chinese New Year | special_non_working |
| 2026-02-25 | EDSA Revolution Anniversary | special_non_working |
| 2026-03-20 | Eid'l Fitr | regular |
| 2026-04-02 | Maundy Thursday | regular |
| 2026-04-03 | Good Friday | regular |
| 2026-04-04 | Black Saturday | special_non_working |
| 2026-04-09 | Araw ng Kagitingan | regular |
| 2026-05-01 | Labor Day | regular |
| 2026-05-27 | Eid'l Adha | regular |
| 2026-06-12 | Independence Day | regular |
| 2026-08-21 | Ninoy Aquino Day | special_non_working |
| 2026-08-31 | National Heroes Day | regular |
| 2026-11-01 | All Saints' Day | special_non_working |
| 2026-11-02 | All Souls' Day | special_non_working |
| 2026-11-30 | Bonifacio Day | regular |
| 2026-12-08 | Feast of Immaculate Conception | special_non_working |
| 2026-12-24 | Christmas Eve | special_non_working |
| 2026-12-25 | Christmas Day | regular |
| 2026-12-30 | Rizal Day | regular |
| 2026-12-31 | Last Day of the Year | special_non_working |

## 5. LEAVE TYPES (8)

| Code | Name | Balance | Paid | Document | Convert Sep | Convert YE |
|---|---|---|---|---|---|---|
| VL | Vacation Leave | 15.0 | true | false | true | false |
| SL | Sick Leave | 15.0 | true | true (3+ days) | false | false |
| SIL | Service Incentive Leave | 5.0 | true | false | true | true |
| ML | Maternity Leave | 105.0 | true | true | false | false |
| PL | Paternity Leave | 7.0 | true | true | false | false |
| SPL | Solo Parent Leave | 7.0 | true | true | false | false |
| VAWC | VAWC Leave | 10.0 | true | true | false | false |
| SLW | Special Leave for Women | 60.0 | true | true | false | false |

## 6. CHART OF ACCOUNTS (~45 accounts)

| Code | Name | Type | Normal | Parent |
|---|---|---|---|---|
| 1000 | Assets | asset | debit | — |
| 1010 | Cash on Hand | asset | debit | 1000 |
| 1020 | Cash in Bank | asset | debit | 1000 |
| 1030 | Petty Cash | asset | debit | 1000 |
| 1100 | Accounts Receivable | asset | debit | 1000 |
| 1200 | Inventory - Raw Materials | asset | debit | 1000 |
| 1210 | Inventory - Finished Goods | asset | debit | 1000 |
| 1220 | Inventory - Packaging | asset | debit | 1000 |
| 1230 | Inventory - Spare Parts | asset | debit | 1000 |
| 1300 | Prepaid Expenses | asset | debit | 1000 |
| 1310 | VAT Input | asset | debit | 1000 |
| 1400 | Property Plant & Equipment | asset | debit | 1000 |
| 1410 | Accumulated Depreciation | asset | credit | 1000 |
| 2000 | Liabilities | liability | credit | — |
| 2010 | Accounts Payable | liability | credit | 2000 |
| 2020 | SSS Payable | liability | credit | 2000 |
| 2030 | PhilHealth Payable | liability | credit | 2000 |
| 2040 | Pag-IBIG Payable | liability | credit | 2000 |
| 2050 | Withholding Tax Payable | liability | credit | 2000 |
| 2060 | VAT Output | liability | credit | 2000 |
| 2070 | Accrued Expenses | liability | credit | 2000 |
| 2080 | 13th Month Pay Payable | liability | credit | 2000 |
| 2100 | Loans Payable | liability | credit | 2000 |
| 3000 | Equity | equity | credit | — |
| 3010 | Capital Stock | equity | credit | 3000 |
| 3020 | Retained Earnings | equity | credit | 3000 |
| 4000 | Revenue | revenue | credit | — |
| 4010 | Sales Revenue | revenue | credit | 4000 |
| 4020 | Other Income | revenue | credit | 4000 |
| 5000 | Cost of Goods Sold | expense | debit | — |
| 5010 | Direct Materials | expense | debit | 5000 |
| 5020 | Direct Labor | expense | debit | 5000 |
| 5030 | Manufacturing Overhead | expense | debit | 5000 |
| 6000 | Operating Expenses | expense | debit | — |
| 6010 | Salaries & Wages Expense | expense | debit | 6000 |
| 6015 | Overtime Expense | expense | debit | 6000 |
| 6020 | Employee Benefits Expense | expense | debit | 6000 |
| 6030 | SSS Expense (Employer) | expense | debit | 6000 |
| 6040 | PhilHealth Expense (Employer) | expense | debit | 6000 |
| 6050 | Pag-IBIG Expense (Employer) | expense | debit | 6000 |
| 6060 | Utilities Expense | expense | debit | 6000 |
| 6070 | Rent Expense | expense | debit | 6000 |
| 6080 | Depreciation Expense | expense | debit | 6000 |
| 6090 | Office Supplies Expense | expense | debit | 6000 |
| 6100 | Repairs & Maintenance Expense | expense | debit | 6000 |
| 6110 | Transportation Expense | expense | debit | 6000 |

## 7. DEFECT TYPES (Injection Molding, 11)

| Code | Name | Description |
|---|---|---|
| FL | Flash | Excess material at mold parting line |
| SS | Short Shot | Incomplete fill, part not fully formed |
| SM | Sink Mark | Depression on surface from uneven cooling |
| WL | Weld Line | Visible line where two flow fronts meet |
| BM | Burn Mark | Discoloration from trapped gas |
| WP | Warping | Part is bent or twisted |
| DM | Dimensional | Measurement out of tolerance |
| VS | Visual/Scratch | Surface scratches or scuffs |
| CR | Crack | Stress cracks |
| MI | Metal Insert | Insert misaligned or missing |
| OT | Other | Describe in remarks |

## 8. SSS CONTRIBUTION TABLE

> Seed the FULL current SSS table from official SSS website (~30 brackets). Format below shows structure. The system allows admin to update through `pages/admin/gov-tables.tsx`.

```
agency: sss, effective_date: 2024-01-01

Example brackets (seed all ~30 from SSS official table):
  bracket_min  bracket_max   ee_amount   er_amount
  0.00         4249.99       180.00      390.00
  4250.00      4749.99       202.50      437.50
  4750.00      5249.99       225.00      485.00
  5250.00      5749.99       247.50      532.50
  ...
  29750.00     999999.99     1350.00     2910.00
```

Computation: `salary` falls into one bracket → use that bracket's ee_amount and er_amount directly.

## 9. PHILHEALTH TABLE

```
agency: philhealth, effective_date: 2024-01-01
Single rate-based row:
  bracket_min: 10000.00 (floor)
  bracket_max: 100000.00 (ceiling)
  ee_amount: 0.0225 (2.25% — RATE, not fixed amount)
  er_amount: 0.0225 (2.25% — RATE)
```

Computation:
```
basis = clamp(salary, 10000, 100000)
total_premium = basis * 0.05
ee = total_premium / 2
er = total_premium / 2
```

## 10. PAG-IBIG TABLE

```
agency: pagibig, effective_date: 2024-01-01

Two brackets (rate-based):
  bracket_min  bracket_max   ee_rate    er_rate
  0.00         1500.00       0.01       0.02     (1% / 2%)
  1500.01      999999.99     0.02       0.02     (2% / 2%)

Max EE contribution: ₱200/month (based on 10000 ceiling).
```

Computation:
```
basis = min(salary, 10000)
ee = basis * (bracket ee_rate)
er = basis * (bracket er_rate)
```

## 11. BIR WITHHOLDING TAX TABLE (TRAIN Law, Semi-Monthly)

```
agency: bir, effective_date: 2018-01-01

Semi-monthly brackets:
  bracket_min   bracket_max    fixed_tax    rate_on_excess
  0.00          10416.00       0.00         0.00     (exempt)
  10416.01      16666.00       0.00         0.15     (15% of excess over 10416)
  16666.01      33332.00       937.50       0.20     (937.50 + 20% of excess over 16666)
  33332.01      83332.00       4270.83      0.25
  83332.01      333332.00      16770.83     0.30
  333332.01     999999.99      91770.83     0.35
```

Computation:
```
taxable = gross_pay - sss_ee - philhealth_ee - pagibig_ee
find bracket where bracket_min <= taxable <= bracket_max
tax = fixed_tax + (rate_on_excess * (taxable - bracket_min))
```

## 12. WORKFLOW DEFINITIONS (16)

| Workflow Type | Steps |
|---|---|
| leave_request | [{order:1, role:"department_head", label:"Noted by"}, {order:2, role:"hr_officer", label:"Approved by"}] |
| overtime_request | [{order:1, role:"department_head", label:"Approved by"}] |
| cash_advance | [{order:1, role:"department_head", label:"Noted by"}, {order:2, role:"finance_officer", label:"Reviewed by"}, {order:3, role:"vice_president", label:"Approved by"}] |
| company_loan | [{order:1, role:"department_head", label:"Noted by"}, {order:2, role:"manager", label:"Checked by"}, {order:3, role:"finance_officer", label:"Reviewed by"}, {order:4, role:"vice_president", label:"Approved by"}] |
| purchase_request | [{order:1, role:"department_head", label:"Noted by"}, {order:2, role:"manager", label:"Checked by"}, {order:3, role:"purchasing_officer", label:"Reviewed by"}, {order:4, role:"vice_president", label:"Approved by"}] |
| purchase_order | [{order:1, role:"vice_president", label:"Approved by"}] (only if total ≥ ₱50,000) |
| bill_payment | [{order:1, role:"finance_officer", label:"Prepared by"}, {order:2, role:"vice_president", label:"Approved by"}] |
| salary_adjustment | [{order:1, role:"manager", label:"Checked by"}, {order:2, role:"vice_president", label:"Approved by"}] |
| department_transfer | [{order:1, role:"department_head", label:"Old Dept Head"}, {order:2, role:"department_head", label:"New Dept Head"}] |
| work_order | [{order:1, role:"production_manager", label:"Approved by"}] |
| ncr | [{order:1, role:"qc_head", label:"Reviewed by"}, {order:2, role:"qc_manager", label:"Approved by"}] |
| maintenance_request | [{order:1, role:"maintenance_head", label:"Assigned by"}] |
| asset_disposal | [{order:1, role:"department_head", label:"Noted by"}, {order:2, role:"manager", label:"Checked by"}, {order:3, role:"finance_officer", label:"Reviewed by"}, {order:4, role:"vice_president", label:"Approved by"}] |
| payroll | [{order:1, role:"hr_officer", label:"Reviewed by"}, {order:2, role:"finance_officer", label:"Confirmed by"}] |
| separation_clearance | [{order:1, role:"department_head"}, {order:2, role:"warehouse_head"}, {order:3, role:"maintenance_head"}, {order:4, role:"finance_officer"}, {order:5, role:"hr_officer"}] |
| 8d_report | [{order:1, role:"qc_manager", label:"Reviewed by"}, {order:2, role:"vice_president", label:"Approved by"}] |

## 13. DEMO ACCOUNTS (12, password: `password`)

| Email | Name | Role | Department |
|---|---|---|---|
| admin@ogami.test | System Administrator | system_admin | — |
| hr@ogami.test | Maria Santos | hr_officer | HR |
| finance@ogami.test | Ana Reyes | finance_officer | FIN |
| production@ogami.test | Ricardo Tanaka | production_manager | PROD |
| ppc@ogami.test | Pedro Garcia | ppc_head | PPC |
| purchasing@ogami.test | Elena Cruz | purchasing_officer | PUR |
| warehouse@ogami.test | Carlos Mendoza | warehouse_staff | WH |
| qc@ogami.test | Rosa Villareal | qc_inspector | QC |
| maintenance@ogami.test | Juan Bautista | maintenance_tech | MAINT |
| impex@ogami.test | Lisa Yamamoto | impex_officer | IMPEX |
| depthead@ogami.test | Roberto Santos | department_head | PROD |
| employee@ogami.test | Manuel Cruz | employee | PROD |

## 14. DEMO PRODUCTS (8) with BOMs

| Part Number | Name | BOM (raw materials) |
|---|---|---|
| WB-001 | Wiper Bushing (Standard) | 15g Resin A, 2g Black Colorant, 1 Poly Bag |
| WB-002 | Wiper Bushing (Heavy Duty) | 20g Resin A, 3g Black Colorant, 1 Poly Bag |
| PC-001 | Pivot Cap Cover Type A | 25g Resin B, 3g White Colorant, 1 Poly Bag |
| PC-002 | Pivot Cap Cover Type B | 25g Resin B, 3g Gray Colorant, 1 Poly Bag |
| RC-001 | Relay Cover Standard | 30g Resin C, 2g Black Colorant, 1 Small Metal Insert, 1 Poly Bag |
| BB-001 | Wiper Motor Bobbin | 12g Resin D, 1 Metal Core, 1 Poly Bag |
| BU-001 | Windshield Wiper Bushing | 18g Resin A, 2g White Colorant, 1 Poly Bag |
| RC-002 | Relay Cover Large | 45g Resin C, 3g Black Colorant, 2 Large Metal Inserts, 1 Poly Bag |

## 15. DEMO CUSTOMERS (5)

| Name | Payment Terms |
|---|---|
| Toyota Motor Philippines, Inc. | Net 30 |
| Nissan Philippines, Inc. | Net 30 |
| Honda Philippines, Inc. | Net 30 |
| Suzuki Philippines, Inc. | Net 45 |
| Yamaha Motor Philippines, Inc. | Net 45 |

## 16. DEMO RAW MATERIALS (12)

| Code | Name | Type | UOM | Std Cost (₱) |
|---|---|---|---|---|
| RM-001 | Plastic Resin Type A (ABS) | raw_material | kg | 120.00 |
| RM-002 | Plastic Resin Type B (PP) | raw_material | kg | 95.00 |
| RM-003 | Plastic Resin Type C (PA) | raw_material | kg | 150.00 |
| RM-004 | Plastic Resin Type D (POM) | raw_material | kg | 180.00 |
| RM-010 | Black Colorant | raw_material | kg | 250.00 |
| RM-011 | White Colorant | raw_material | kg | 280.00 |
| RM-012 | Gray Colorant | raw_material | kg | 260.00 |
| RM-050 | Small Metal Insert | raw_material | pcs | 5.50 |
| RM-051 | Large Metal Insert | raw_material | pcs | 8.00 |
| RM-052 | Metal Core (Bobbin) | raw_material | pcs | 12.00 |
| PKG-001 | Standard Poly Bag | packaging | pcs | 0.50 |
| PKG-002 | Shipping Box (50 pcs) | packaging | pcs | 15.00 |

## 17. DEMO VENDORS (8)

| Name | Country | Materials Supplied |
|---|---|---|
| Ogami Co., Ltd. | Japan | Resin A, Resin D, Metal Inserts |
| Taiwan Plastics Corp. | Taiwan | Resin B, Resin C |
| China Chemical Supply | China | Colorants |
| Phil Metals Inc. | Philippines | Metal Cores |
| Cavite Packaging Solutions | Philippines | Poly Bags, Boxes |
| Meralco | Philippines | Electricity |
| Cavite Water District | Philippines | Water |
| ABC Tooling Services | Philippines | External mold repair |

---

## SEEDER ORDER

Run sub-seeders in this order in `DatabaseSeeder.php`:

```
1. RoleSeeder + PermissionSeeder + RolePermissionSeeder
2. SettingsSeeder
3. WorkflowDefinitionSeeder
4. DocumentSequenceSeeder
5. DepartmentSeeder
6. PositionSeeder
7. ShiftSeeder
8. HolidaySeeder
9. LeaveTypeSeeder
10. GovernmentTableSeeder (SSS, PhilHealth, Pag-IBIG, BIR)
11. ChartOfAccountsSeeder
12. DefectTypeSeeder
13. WarehouseSeeder (with zones + locations)
14. ItemCategorySeeder
15. (in Sprint 8) DemoDataSeeder — 50 employees, attendance, payrolls, vendors, customers, products, BOMs, sales orders, work orders, inspections, etc.
```
