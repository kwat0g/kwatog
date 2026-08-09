import os
import glob
import re

files = glob.glob('src/pages/**/*.tsx', recursive=True)

# Regex to find Modal elements and their children
# This is tricky with regex, so we'll use a slightly hacky string replacement strategy

for f in files:
    with open(f, 'r') as file:
        content = file.read()
    
    if '<Modal' not in content:
        continue
        
    # We want to find common footer patterns like:
    # <div className="flex justify-end gap-2 pt-3 border-t border-default">
    # ...
    # </div>
    # </Modal>
    
    # We can match from <div className="flex .*? border-t .*?"> up to </Modal>
    # and extract it into a footer prop.
    
    # Actually, a simpler way is to just define a ModalFooter in Modal.tsx,
    # and then in the files, replace the custom div with <ModalFooter>.
    
    # Let's see if we can just define ModalFooter first.
