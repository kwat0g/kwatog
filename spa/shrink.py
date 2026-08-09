import re
import glob
import sys

def process(file_path):
    with open(file_path, 'r') as f:
        content = f.read()

    # Find the columns array
    # Very hard to correctly parse TSX.
    # Let's just find `cell: ` to the next `}` or `,` and if there's a button, replace.
    # Actually, a simpler regex:
    # Match any button that has `size="sm"` and is indented deeply (table buttons).
    # Specifically we want to replace `size="sm"` with `size="xs"` for Approve, Reject, Edit, Delete buttons that occur inside tables.
    
    # We can just look for `size="sm"` on lines containing `<Button` and replace with `size="xs"`
    # IF the line has 6 or more spaces of indentation.
    lines = content.split('\n')
    changed = False
    in_actions = False
    
    for i, line in enumerate(lines):
        if "key: 'actions'" in line or 'key: "actions"' in line:
            in_actions = True
        elif line.strip() == "}," and in_actions:
            # roughly end of actions object
            pass
            
        if '<Button' in line and 'size="sm"' in line:
            if 'Approve' in line or 'Reject' in line or in_actions:
                lines[i] = line.replace('size="sm"', 'size="xs"')
                changed = True
                
    if changed:
        with open(file_path, 'w') as f:
            f.write('\n'.join(lines))
        print(f"Updated {file_path}")

for p in glob.glob('src/pages/**/*.tsx', recursive=True):
    process(p)
