import os
import glob
import re

files = glob.glob('src/pages/**/*.tsx', recursive=True)
modal_footers = []

for f in files:
    with open(f, 'r') as file:
        content = file.read()
        if '<Modal' in content:
            # find div inside Modal that looks like a footer
            matches = re.finditer(r'<div className="[^"]*border-t[^"]*".*?>', content)
            for m in matches:
                modal_footers.append((f, m.group(0)))

for f, m in modal_footers:
    print(f"{f}: {m}")
