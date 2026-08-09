import os
import glob
import re

def fix_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Look for the EmptyState block and the StatCard block
    # The StatCard block starts with `{data && data.data.length > 0 && (\n  <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">`
    # or similar spacing.
    
    # We want to match:
    # 1. EmptyState block: `{data && data.data.length === 0 && ( ... )}`
    # 2. StatCard block: `{data && data.data.length > 0 && ( ... StatCards ... )}`
    
    empty_state_pattern = r'\{data\s*&&\s*data\.data\.length\s*===\s*0\s*&&\s*\([\s\S]*?<EmptyState[\s\S]*?/>\s*\)\}'
    
    # We use a non-greedy match that looks for the exact grid class
    statcard_block_pattern = r'\{data\s*&&\s*data\.data\.length\s*>\s*0\s*&&\s*\(\s*<div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">[\s\S]*?</div>\s*\)\}'
    
    empty_state_match = re.search(empty_state_pattern, content)
    statcard_block_match = re.search(statcard_block_pattern, content)
    
    if not empty_state_match or not statcard_block_match:
        return False
        
    empty_str = empty_state_match.group(0)
    statcard_str = statcard_block_match.group(0)
    
    # Check order: is empty_str before statcard_str?
    if content.find(empty_str) > content.find(statcard_str):
        # Already ordered correctly? Wait, we still need to fix the condition.
        pass

    # Modify the statcard block to remove `data.data.length > 0 && `
    new_statcard_str = statcard_str.replace('data.data.length > 0 && ', '')
    
    # Remove both blocks from content safely
    # First, let's just do a replacement. We will replace both matches with empty strings, 
    # then insert them at the original position of the first one.
    
    start_pos = min(content.find(empty_str), content.find(statcard_str))
    
    # Remove them
    content_without = content.replace(empty_str, '').replace(statcard_str, '')
    
    # Insert in correct order: StatCard first, then EmptyState
    # Wait, the StatCard block should now just be `{data && (` ... `)}`
    combined_str = f"{new_statcard_str}\n\n{empty_str}"
    
    new_content = content_without[:start_pos] + combined_str + content_without[start_pos:]
    
    if new_content != content:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        return True
    return False

# Find all index files in pages
files = glob.glob("src/pages/**/index.tsx", recursive=True)
count = 0
for f in files:
    if fix_file(f):
        print(f"Fixed {f}")
        count += 1
        
print(f"Total files fixed: {count}")
