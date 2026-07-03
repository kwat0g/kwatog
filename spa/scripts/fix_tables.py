import os
import re

directory = "/home/kwat0g/Desktop/kwatog/spa/src"
fixed_files = 0

def fix_th(match):
    attrs = match.group(1) or ""
    if not attrs.strip():
        return '<th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">'

    class_match = re.search(r'className="([^"]*)"', attrs)
    if class_match:
        classes = class_match.group(1).split()
        
        required = ["text-2xs", "uppercase", "tracking-wider", "text-muted", "font-medium"]
        classes = [c for c in classes if c not in ['text-xs', 'text-sm', 'text-base', 'text-lg']]
        
        for req in required:
            if req not in classes:
                classes.append(req)
                
        classes = [c if not c.startswith('h-') else 'h-8' for c in classes]
        if 'h-8' not in classes:
            classes.insert(0, 'h-8')
            
        new_class_str = ' '.join(classes)
        new_attrs = attrs[:class_match.start(1)] + new_class_str + attrs[class_match.end(1):]
        return f"<th {new_attrs}>"
    else:
        return f'<th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium" {attrs.strip()}>'

def fix_td(m):
    attrs = m.group(1) or ""
    class_match = re.search(r'className="([^"]*)"', attrs)
    if class_match:
        classes = class_match.group(1).split()
        if 'text-right' in classes:
            if 'font-mono' not in classes:
                classes.append('font-mono')
            if 'tabular-nums' not in classes:
                classes.append('tabular-nums')
            new_class_str = ' '.join(classes)
            new_attrs = attrs[:class_match.start(1)] + new_class_str + attrs[class_match.end(1):]
            return f"<td {new_attrs}>"
    return m.group(0)

for root, _, files in os.walk(directory):
    for file in files:
        if file.endswith('.tsx'):
            filepath = os.path.join(root, file)
            with open(filepath, 'r') as f:
                content = f.read()
            
            new_content = re.sub(r'<th\b([^>]*)>', fix_th, content)
            new_content = re.sub(r'<td\b([^>]*)>', fix_td, new_content)

            if new_content != content:
                with open(filepath, 'w') as f:
                    f.write(new_content)
                fixed_files += 1

print(f"Fixed {fixed_files} files.")
