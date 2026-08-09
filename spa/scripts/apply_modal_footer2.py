import os
import glob
import re

files = glob.glob('src/pages/**/*.tsx', recursive=True)

modal_import_re = re.compile(r'import\s+\{([^}]*\bModal\b[^}]*)\}\s+from\s+[\'"]@/components/ui(/Modal)?[\'"];?')

known_classes = [
    "flex justify-end gap-2 pt-3 border-t border-default",
    "flex justify-end gap-2 pt-2 border-t border-default",
    "flex justify-end pt-2 border-t border-default",
    "flex gap-2 pt-3 border-t border-default",
    "flex justify-end gap-2 pt-3 mt-3 border-t border-default",
    "flex justify-end gap-2 pt-3 mt-4 border-t border-default",
    "flex justify-end gap-2 pt-4 border-t border-default mt-4",
    "flex items-center justify-end gap-2 pt-2 border-t border-default",
    "flex items-center justify-end gap-2 pt-4 border-t border-default",
    "flex items-center justify-between gap-3 pt-2 border-t border-default",
    "flex justify-end gap-2 pt-4 border-t border-default",
]

def find_closing_div(text, start_idx):
    open_divs = 1
    i = start_idx
    while i < len(text) and open_divs > 0:
        if text[i:i+4] == '<div' and not text[i-1:i] == '<': # rough check
            open_divs += 1
        elif text[i:i+6] == '</div>':
            open_divs -= 1
            if open_divs == 0:
                return i
        i += 1
    return -1

for filepath in files:
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    if '<Modal' not in content:
        continue
        
    original = content
    
    for cls in known_classes:
        target = f'<div className="{cls}">'
        
        while True:
            start_idx = content.find(target)
            if start_idx == -1:
                break
                
            end_div_idx = find_closing_div(content, start_idx + len(target))
            if end_div_idx != -1:
                if 'justify-between' in cls:
                    repl_open = '<ModalFooter className="justify-between">'
                elif 'flex gap-2' in cls and 'justify-end' not in cls:
                    repl_open = '<ModalFooter className="justify-start">'
                else:
                    repl_open = '<ModalFooter>'
                
                # Replace the closing div first so index doesn't shift for opening div
                content = content[:end_div_idx] + '</ModalFooter>' + content[end_div_idx+6:]
                content = content[:start_idx] + repl_open + content[start_idx+len(target):]
            else:
                break # Should not happen

    if content != original:
        # Update imports
        import_match = modal_import_re.search(content)
        if import_match:
            imp_str = import_match.group(0)
            if 'ModalFooter' not in imp_str:
                new_imp_str = imp_str.replace('Modal', 'Modal, ModalFooter')
                content = content.replace(imp_str, new_imp_str)
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Updated {filepath}")
