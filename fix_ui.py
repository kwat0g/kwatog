import os
import re

def fix_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Skip landing pages
    if 'pages/landing/' in filepath:
        return

    original = content

    # Fix fonts
    content = re.sub(r'\bfont-(semibold|bold|extrabold|black)\b', 'font-medium', content)

    # Fix shadows
    content = re.sub(r'\bshadow(-sm|-md|-lg|-xl|-2xl|-xs)\b', '', content)
    # Some extra spaces might be left, let's clean double spaces in classes
    content = re.sub(r'  +', ' ', content)

    # Fix border radius (except for Modals where rounded-lg is allowed, but we don't have modals in this list)
    content = re.sub(r'\brounded-(lg|xl|2xl|3xl)\b', 'rounded-md', content)

    # Fix border-border
    content = re.sub(r'\bborder-border\b', 'border-default', content)

    if content != original:
        print(f"Fixed {filepath}")
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)

def scan_and_fix(directory):
    for root, _, files in os.walk(directory):
        for file in files:
            if not file.endswith(('.tsx', '.ts')):
                continue
            filepath = os.path.join(root, file)
            fix_file(filepath)

if __name__ == '__main__':
    scan_and_fix('/home/kwat0g/Desktop/kwatog/spa/src')
