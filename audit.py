import os
import re
from collections import defaultdict

def scan_directory(directory):
    violations = defaultdict(list)
    
    patterns = {
        'gray_colors': re.compile(r'\b(bg|text|border|ring)-gray-\d{2,3}\b'),
        'wrong_weights': re.compile(r'\bfont-(semibold|bold|extrabold|black)\b'),
        'shadows': re.compile(r'\bshadow(-sm|-md|-lg|-xl|-2xl|)\b'),
        'wrong_radius': re.compile(r'\brounded-(lg|xl|2xl|3xl|full)\b'),
    }

    allowed_shadows = ['shadow-focus', 'shadow-menu']
    
    for root, _, files in os.walk(directory):
        for file in files:
            if not file.endswith(('.tsx', '.ts')):
                continue
                
            filepath = os.path.join(root, file)
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
                
                # Check for gray colors
                if patterns['gray_colors'].search(content):
                    violations['gray_colors'].append(filepath)
                    
                # Check for font weights
                if patterns['wrong_weights'].search(content):
                    violations['wrong_weights'].append(filepath)
                    
                # Check for shadows
                shadow_matches = patterns['shadows'].finditer(content)
                has_invalid_shadow = False
                for match in shadow_matches:
                    if match.group(0) not in allowed_shadows:
                        # Only flag if it's outside allowed list
                        # Need to double check full string if it's e.g. drop-shadow, but for now simple check
                        has_invalid_shadow = True
                        break
                if has_invalid_shadow:
                    violations['shadows'].append(filepath)

                # Radius is tricky since modals use rounded-lg and avatars use rounded-full.
                # But we can flag them to review.
                if patterns['wrong_radius'].search(content):
                    violations['wrong_radius'].append(filepath)
                    
    for k, v in violations.items():
        print(f"=== {k} ({len(v)} files) ===")
        for filepath in set(v):
            print(f"  {filepath}")
            
if __name__ == '__main__':
    scan_directory('/home/kwat0g/Desktop/kwatog/spa/src')
