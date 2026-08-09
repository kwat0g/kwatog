import glob
import re

files = glob.glob("src/pages/warehouse/*.tsx")
for filepath in files:
    with open(filepath, 'r') as f:
        content = f.read()

    # We want to remove backTo="/..." and backLabel="..."
    # First find backTo
    new_content = re.sub(r'\s+backTo="[^"]+"', '', content)
    new_content = re.sub(r'\s+backLabel="[^"]+"', '', new_content)
    
    if new_content != content:
        with open(filepath, 'w') as f:
            f.write(new_content)
        print(f"Fixed {filepath}")

