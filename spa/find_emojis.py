import os
import glob

emoji_ranges = [
    (0x1F600, 0x1F64F), # Emoticons
    (0x1F300, 0x1F5FF), # Misc Symbols and Pictographs
    (0x1F680, 0x1F6FF), # Transport and Map
    (0x1F1E6, 0x1F1FF), # Regional indicator symbol
    (0x2600, 0x26FF),   # Misc symbols
    (0x2700, 0x27BF),   # Dingbats
    (0xFE00, 0xFE0F),   # Variation Selectors
    (0x1F900, 0x1F9FF), # Supplemental Symbols and Pictographs
    (0x1FA70, 0x1FAFF), # Symbols and Pictographs Extended-A
]

def has_emoji(text):
    for char in text:
        code = ord(char)
        for start, end in emoji_ranges:
            if start <= code <= end:
                return True
    return False

for filepath in glob.glob('src/**/*.tsx', recursive=True):
    with open(filepath, 'r') as f:
        for i, line in enumerate(f):
            if has_emoji(line):
                print(f"{filepath}:{i+1}:{line.strip()}")
