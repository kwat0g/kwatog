import re

files = [
    "src/pages/warehouse/stock-count.tsx",
    "src/pages/warehouse/transfers.tsx",
    "src/pages/warehouse/picking.tsx"
]

for filepath in files:
    with open(filepath, 'r') as f:
        content = f.read()

    # 1. Remove backTo and backLabel from PageHeader
    content = re.sub(r'backTo="[^"]+"\s*', '', content)
    content = re.sub(r'backLabel="[^"]+"\s*', '', content)
    
    # 2. Fix the master list outlines
    old_class_pat = r"className=\{`w-full text-left px-2 py-1.5 text-xs rounded-md transition-colors cursor-pointer \$\{focusRingInset\} \$\{\s+([^?]+)\? 'bg-accent/10 text-accent border border-accent/20' : 'text-muted hover:text-primary hover:bg-elevated'\s+\}`\}"
    new_class_template = r"className={`w-full text-left px-2 py-1.5 text-xs rounded-md transition-colors cursor-pointer border ${focusRingInset} ${\n             \1 ? 'bg-accent/10 text-accent border-accent/30 shadow-sm' : 'bg-surface border-default text-secondary hover:border-subtle hover:text-primary hover:bg-elevated'\n            }`}"
    
    content = re.sub(old_class_pat, new_class_template, content)

    with open(filepath, 'w') as f:
        f.write(content)

print("Done")
