import re
import glob

def strip_font_weight(filepath):
    if "landing/" in filepath: return
    
    with open(filepath, 'r') as f:
        content = f.read()
    
    # We want to remove font-medium, font-semibold, font-bold, font-black
    # ONLY if font-display is also on the same line/className.
    # A simple regex for lines containing font-display:
    
    lines = content.split('\n')
    changed = False
    for i, line in enumerate(lines):
        if 'font-display' in line:
            new_line = re.sub(r'\bfont-(medium|semibold|bold|black)\b', '', line)
            # cleanup double spaces left behind
            new_line = re.sub(r'  +', ' ', new_line)
            if new_line != line:
                lines[i] = new_line
                changed = True
                
    if changed:
        with open(filepath, 'w') as f:
            f.write('\n'.join(lines))
        print(f"Fixed fonts in {filepath}")

for filepath in glob.glob('src/**/*.tsx', recursive=True):
    strip_font_weight(filepath)
