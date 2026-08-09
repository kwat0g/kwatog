import re
files = [
    "src/pages/accounting/coa/index.tsx",
    "src/pages/accounting/credit-notes/index.tsx",
    "src/pages/accounting/journal-entries/create.tsx",
    "src/pages/accounting/invoices/create.tsx",
    "src/pages/accounting/bills/create.tsx",
    "src/pages/inventory/material-issues/create.tsx",
    "src/pages/return-management/create.tsx"
]
for f in files:
    try:
        with open(f, 'r') as fp:
            content = fp.read()
        # replace h-8 with h-row inside grid items that look like table rows
        # specifically <div className="... h-8 ..." that are table headers or rows
        content = re.sub(r'(\bclassName="[^"]*)\bh-8\b', r'\1h-row', content)
        with open(f, 'w') as fp:
            fp.write(content)
        print(f"Fixed {f}")
    except Exception as e:
        print(f"Error {f}: {e}")
