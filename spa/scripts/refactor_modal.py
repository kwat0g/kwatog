import os
import glob
import re

files = glob.glob('src/pages/**/*.tsx', recursive=True)

for filepath in files:
    with open(filepath, 'r') as f:
        content = f.read()

    original_content = content
    
    # Check if there's a modal footer pattern
    # Typical: <div className="flex [items-center ]*justify-end gap-2 pt-[2-4].*?border-t border-default[^"]*">
    # Or similar combinations
    # Let's use a regex to find these divs
    
    # We want to replace `<div className="... border-t ...">` with `<ModalFooter>`
    # and `</div>` with `</ModalFooter>` but ONLY for the modal footers.
    # Since we can't easily parse HTML with regex, we can replace specific known strings.

    # Find all divs that look like footers:
    patterns = [
        r'<div className="[^"]*border-t[^"]*flex[^"]*justify-end[^"]*">',
        r'<div className="[^"]*flex[^"]*justify-end[^"]*border-t[^"]*">',
        r'<div className="[^"]*border-t[^"]*pt-[0-9][^"]*">',
        r'<div className="[^"]*pt-[0-9][^"]*border-t[^"]*">',
    ]
    
    # Wait, it's safer to just look inside files that import Modal.
    if '<Modal' not in content:
        continue
        
    def replacer(match):
        return match.group(0).replace('div', 'ModalFooter').replace('className="', 'className="').replace(' pt-3', '').replace(' mt-3', '').replace(' border-t', '').replace(' border-default', '').replace(' flex justify-end gap-2', '').replace(' pt-2', '').replace(' mt-4', '')
        # Actually, if ModalFooter handles the flex and justify-end by default, we don't need any classNames unless they are special.
    
    # A better approach: 
    # Just do this semi-manually or with a very targeted script.
    
    # Let's print out what we found
    for p in patterns:
        for m in re.finditer(p, content):
            print(f"File {filepath}: {m.group(0)}")
