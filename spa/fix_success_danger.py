import re
import glob

def fix_semantic_colors(filepath):
    if "landing/" in filepath: return
    
    with open(filepath, 'r') as f:
        content = f.read()
    
    # We want to replace `text-success` with `text-success-fg` but NOT if it's already `text-success-fg` or `text-success-bg`.
    # Also for warning, danger, info, purple.
    # Same for `bg-success` -> `bg-success-bg`
    
    changed = False
    
    for color in ['success', 'warning', 'danger', 'info', 'purple']:
        # Match word boundary text-color but NOT followed by -fg or -bg
        pattern_text = rf'\btext-{color}\b(?!-fg|-bg)'
        if re.search(pattern_text, content):
            content = re.sub(pattern_text, f'text-{color}-fg', content)
            changed = True
            
        pattern_bg = rf'\bbg-{color}\b(?!-bg|-fg)'
        if re.search(pattern_bg, content):
            content = re.sub(pattern_bg, f'bg-{color}-bg', content)
            changed = True
            
        pattern_border = rf'\bborder-{color}\b'
        if re.search(pattern_border, content):
            # DESIGN-SYSTEM.md doesn't have border-success. It only says "Every variant uses its -bg/-fg pair — never -DEFAULT on canvas"
            # It doesn't specify border-success. Maybe border-success doesn't exist? Wait, they said "-DEFAULT on canvas".
            # We'll just fix text and bg.
            pass

    if changed:
        with open(filepath, 'w') as f:
            f.write(content)
        print(f"Fixed semantic colors in {filepath}")

for filepath in glob.glob('src/**/*.tsx', recursive=True):
    fix_semantic_colors(filepath)
