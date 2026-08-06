import os
import re

def process_file(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    modified = False

    # We only want to target files that use DataTable to avoid messing with other UI
    if 'DataTable' in content and 'columns' in content:
        # Search for columns definition
        columns_match = re.search(r'const\s+columns(?:[\s\w<\[\]>,:]+)?\s*=\s*\[(.*?)\];', content, re.DOTALL)
        if columns_match:
            cols_content = columns_match.group(1)
            
            # Replace classes inside the columns definition
            new_cols = cols_content
            new_cols = new_cols.replace('className="font-medium text-accent"', 'className="font-medium"')
            new_cols = new_cols.replace('className="font-mono text-accent"', 'className="font-mono"')
            new_cols = new_cols.replace('className="font-medium text-primary"', 'className="font-medium"')
            new_cols = new_cols.replace('className="font-mono text-primary"', 'className="font-mono"')
            new_cols = new_cols.replace('className="text-accent"', '')
            new_cols = new_cols.replace('className="text-primary"', '')
            
            if new_cols != cols_content:
                content = content.replace(cols_content, new_cols)
                
                # Cleanup empty classNames
                content = content.replace(' className=""', '')
                
                modified = True

    if modified:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Updated {path}")

for root, _, files in os.walk('spa/src'):
    for file in files:
        if file.endswith('.tsx'):
            process_file(os.path.join(root, file))
