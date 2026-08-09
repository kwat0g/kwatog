import re
files_to_fix = [
    "src/pages/accounting/coa/index.tsx",
    "src/pages/admin/activity/index.tsx",
    "src/pages/admin/audit-logs/index.tsx",
    "src/pages/admin/roles/index.tsx",
    "src/pages/admin/users/index.tsx",
    "src/pages/attendance/holidays/index.tsx",
    "src/pages/attendance/overtime/index.tsx",
    "src/pages/attendance/shifts/index.tsx",
    "src/pages/hr/recruitment/applications/index.tsx",
    "src/pages/hr/recruitment/postings/index.tsx",
    "src/pages/inventory/categories/index.tsx",
    "src/pages/inventory/stock-adjustments/index.tsx",
    "src/pages/inventory/stock-levels/index.tsx",
    "src/pages/inventory/warehouse/index.tsx",
    "src/pages/leaves/index.tsx",
    "src/pages/payroll/adjustments/index.tsx",
]

for filepath in files_to_fix:
    with open(filepath, 'r') as f:
        content = f.read()

    # Remove `backTo="..."`
    content = re.sub(r'\s*backTo="[^"]+"', '', content)
    # Remove `backLabel="..."`
    content = re.sub(r'\s*backLabel="[^"]+"', '', content)
    
    with open(filepath, 'w') as f:
        f.write(content)

    print(f"Fixed {filepath}")
