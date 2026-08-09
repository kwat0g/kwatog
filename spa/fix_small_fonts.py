import re
import glob

def strip_font_display(filepath):
    if "landing/" in filepath: return
    
    with open(filepath, 'r') as f:
        content = f.read()
    
    lines = content.split('\n')
    changed = False
    for i, line in enumerate(lines):
        if 'font-display' in line and bool(re.search(r'\b(text-xs|text-sm|text-base)\b', line)):
            new_line = re.sub(r'\bfont-display\b', '', line)
            new_line = re.sub(r'  +', ' ', new_line)
            if new_line != line:
                lines[i] = new_line
                changed = True
                
    if changed:
        with open(filepath, 'w') as f:
            f.write('\n'.join(lines))
        print(f"Fixed small fonts in {filepath}")

for filepath in glob.glob('src/**/*.tsx', recursive=True):
    strip_font_display(filepath)
