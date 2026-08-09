import glob

files = [
    "src/pages/warehouse/map.tsx",
    "src/pages/warehouse/stock-count.tsx",
    "src/pages/warehouse/transfers.tsx",
    "src/pages/warehouse/picking.tsx"
]

old_str = "? 'bg-accent/10 text-accent border border-accent/20' : 'text-muted hover:text-primary hover:bg-elevated'"
new_str = "? 'bg-accent/10 text-accent border border-accent/30 shadow-sm' : 'bg-surface border border-default text-secondary hover:border-subtle hover:text-primary hover:bg-elevated'"

for filepath in files:
    with open(filepath, 'r') as f:
        content = f.read()

    new_content = content.replace(old_str, new_str)
    
    if new_content != content:
        with open(filepath, 'w') as f:
            f.write(new_content)
        print(f"Fixed {filepath}")
    else:
        print(f"No match in {filepath}")

