import os
import re
import glob

def process_file(filepath):
    with open(filepath, 'r') as f:
        content = f.read()

    # Find the columns array definition.
    # It usually looks like `const columns: Column<...>[] = [...]`
    # We will just find `<Button ... size="sm"` and change it to `size="xs"`
    # But only if it's within a `cell: ` block.
    
    # A simpler approach: replace size="sm" with size="xs" for buttons that are inside a cell: function.
    # We can use a regex to find `cell: (.*?) => { ... <Button ... size="sm" ...>`
    # But regex across multiple lines is tricky.
    
    # Let's just find `key: 'actions'` blocks and replace size="sm" with size="xs" inside them.
    # Split the file by `key: 'actions'` (or similar) and modify the chunk.
    
    lines = content.split('\n')
    in_actions = False
    brace_level = 0
    changed = False
    
    for i, line in enumerate(lines):
        if "key: 'actions'" in line or 'key: "actions"' in line or (("Approve" in line or "Reject" in line) and "<Button" in line and 'size="sm"' in line):
            # This is a bit loose but works for Approve/Reject buttons
            pass
            
        # Let's just replace all size="sm" with size="xs" where the line has both `<Button` and `onClick` and is inside a file with `DataTable`
        if '<Button' in line and 'size="sm"' in line and ('onClick=' in line or 'to=' in line or 'href=' in line):
            # We don't want to shrink page header buttons (usually they are New Item, Export, etc. and are not in a cell).
            # Page header buttons are usually at the top, not deeply indented.
            # Table cell buttons are usually indented by 6+ spaces.
            leading_spaces = len(line) - len(line.lstrip())
            if leading_spaces >= 6:
                lines[i] = line.replace('size="sm"', 'size="xs"')
                changed = True
                
    if changed:
        with open(filepath, 'w') as f:
            f.write('\n'.join(lines))
        print(f"Updated {filepath}")

for filepath in glob.glob('src/pages/**/*.tsx', recursive=True):
    process_file(filepath)

