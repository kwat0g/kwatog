import re
with open('src/components/layout/Sidebar.tsx') as f:
    content = f.read()
for match in re.findall(r"to:\s*'([^']+)'", content):
    print(match)
