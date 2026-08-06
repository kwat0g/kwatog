import os
import re

def process_file(filepath):
    with open(filepath, 'r') as f:
        content = f.read()

    # Find all DataTable tags. They might span multiple lines.
    # regex: <DataTable[\s\S]*?>
    
    matches = re.finditer(r'<DataTable([^>]*)>', content)
    for match in matches:
        attributes = match.group(1)
        if 'onRowClick' not in attributes:
            print(f"Missed in {filepath}")

for root, _, files in os.walk('/home/kwat0g/Desktop/kwatog/spa/src/pages'):
    for file in files:
        if file.endswith('.tsx'):
            process_file(os.path.join(root, file))
