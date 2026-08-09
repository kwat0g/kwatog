import glob
import re

files = glob.glob("src/pages/**/index.tsx", recursive=True)

results = []

for filepath in files:
    with open(filepath, 'r') as f:
        content = f.read()
    
    # Check if it's a list page (usually has a DataTable or map over data)
    if 'DataTable' not in content and 'tableKey=' not in content:
        # Some list pages are custom like cards, check if it uses useUrlFilters
        if 'useUrlFilters' not in content:
            continue
            
    # Has FilterBar?
    has_filter_bar = '<FilterBar' in content
    
    has_search = 'onSearch={' in content or 'searchPlaceholder=' in content
    
    if not has_filter_bar or not has_search:
        results.append({
            'file': filepath,
            'has_filter_bar': has_filter_bar,
            'has_search': has_search
        })

print("Pages without search functions:")
for r in results:
    print(f"- {r['file']} (Has FilterBar: {r['has_filter_bar']}, Has Search: {r['has_search']})")
