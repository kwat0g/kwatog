import os
import glob
import re

files = glob.glob('src/pages/**/*.tsx', recursive=True)

# Regex to find Modal import
modal_import_re = re.compile(r'import\s+\{[^}]*\bModal\b[^}]*\}\s+from\s+[\'"]@/components/ui(/Modal)?[\'"];?')

# Typical footer patterns
# <div className="flex justify-end gap-2 pt-3 mt-3 border-t border-default">
# <div className="flex justify-end gap-2 pt-3 border-t border-default">
# <div className="flex gap-2 pt-3 border-t border-default">
# We want to match: `<div className="[^"]*border-t[^"]*"` or similar where it's a direct child of Modal?
# Wait, it's safer to just regex replace specific exact classNames we found from the earlier command.

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
    "flex items-center justify-between gap-3 pt-2 border-t border-default"
]

for filepath in files:
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    original_content = content
    
    if '<Modal' not in content:
        continue
        
    made_changes = False
    
    for cls in known_classes:
        target = f'<div className="{cls}">'
        if target in content:
            # check if it contains flex items-center justify-between
            if 'justify-between' in cls:
                replacement = '<ModalFooter className="justify-between">'
            elif 'flex gap-2' in cls and 'justify-end' not in cls:
                replacement = '<ModalFooter className="justify-start">'
            else:
                replacement = '<ModalFooter>'
                
            # Replace the opening tag
            # We must also ensure we only replace the closing tag of THIS div.
            # However, since they are single-level footers mostly, we can just replace `<div className="...">` with `<ModalFooter>`
            # and then we have to manually fix the closing tag... Wait! Regex can match the block:
            
            # Using regex to find the block
            # This is hard because of nested divs.
            pass
            
    # Instead of full parsing, we can just do string replacements if we are careful,
    # or write a script that tracks braces.
    
    def process_file(text):
        changed = False
        for cls in known_classes:
            target = f'<div className="{cls}">'
            start_idx = text.find(target)
            while start_idx != -1:
                # Find the matching closing </div>
                open_divs = 1
                i = start_idx + len(target)
                while i < len(text) and open_divs > 0:
                    if text[i:i+4] == '<div':
                        open_divs += 1
                    elif text[i:i+6] == '</div>':
                        open_divs -= 1
                    i += 1
                
                if open_divs == 0:
                    end_idx = i
                    # Replace
                    if 'justify-between' in cls:
                        repl_open = '<ModalFooter className="justify-between">'
                    elif 'flex gap-2' in cls and 'justify-end' not in cls:
                        repl_open = '<ModalFooter className="justify-start">'
                    else:
                        repl_open = '<ModalFooter>'
                        
                    inner = text[start_idx + len(target):end_idx-6]
                    new_block = repl_open + inner + '</ModalFooter>'
                    
                    text = text[:start_idx] + new_block + text[end_idx:]
                    changed = True
                    start_idx = text.find(target, start_idx + len(new_block))
                else:
                    break
        return text, changed

    new_content, changed = process_file(content)
    
    if changed:
        # Update imports
        # Find where Modal is imported
        import_match = modal_import_re.search(new_content)
        if import_match:
            imp_str = import_match.group(0)
            if 'ModalFooter' not in imp_str:
                new_imp_str = imp_str.replace('Modal', 'Modal, ModalFooter')
                new_content = new_content.replace(imp_str, new_imp_str)
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print(f"Updated {filepath}")
