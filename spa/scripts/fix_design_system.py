import os
import re

directory = "/home/kwat0g/Desktop/kwatog/spa/src"

replacements = [
    # Typography weights
    (r'\bfont-bold\b', 'font-medium'),
    (r'\bfont-semibold\b', 'font-medium'),
    
    # Shadows (remove standard tailwind shadows)
    (r'\s*\bshadow-(sm|md|lg|xl|2xl|inner|none)\b', ''),
    (r'\s*\bshadow\b', ''), 
    
    # Padding / Spacing
    (r'\bp-6\b', 'p-5'),
    (r'\bp-8\b', 'p-5'),
    (r'\bp-10\b', 'p-5'),
    (r'\bpx-6\b', 'px-5'),
    (r'\bpx-8\b', 'px-5'),
    (r'\bpy-6\b', 'py-4'),
    (r'\bpy-8\b', 'py-5'),
    (r'\bgap-6\b', 'gap-4'),
    (r'\bgap-8\b', 'gap-5'),
    
    # Colors
    (r'\bbg-white\b', 'bg-canvas'),
    (r'\bbg-gray-50\b', 'bg-surface'),
    (r'\bbg-gray-100\b', 'bg-subtle'),
    (r'\bbg-gray-200\b', 'bg-subtle'),
    (r'\bbg-gray-800\b', 'bg-elevated'),
    (r'\bbg-gray-900\b', 'bg-elevated'),
    (r'\btext-gray-900\b', 'text-primary'),
    (r'\btext-gray-800\b', 'text-primary'),
    (r'\btext-gray-700\b', 'text-secondary'),
    (r'\btext-gray-600\b', 'text-secondary'),
    (r'\btext-gray-500\b', 'text-muted'),
    (r'\btext-gray-400\b', 'text-subtle'),
    (r'\bborder-gray-100\b', 'border-subtle'),
    (r'\bborder-gray-200\b', 'border-default'),
    (r'\bborder-gray-300\b', 'border-strong'),
    
    (r'\bbg-blue-500\b', 'bg-accent'),
    (r'\bbg-blue-600\b', 'bg-accent-hover'),
    (r'\btext-blue-500\b', 'text-accent'),
    (r'\btext-blue-600\b', 'text-accent'),
    (r'\bbg-green-500\b', 'bg-success'),
    (r'\btext-green-500\b', 'text-success'),
    (r'\bbg-red-500\b', 'bg-danger'),
    (r'\btext-red-500\b', 'text-danger'),
    (r'\bbg-yellow-500\b', 'bg-warning'),
    (r'\btext-yellow-500\b', 'text-warning'),
    
    # Border radius
    (r'\brounded-xl\b', 'rounded-md'),
    (r'\brounded-2xl\b', 'rounded-md'),
    (r'\brounded-3xl\b', 'rounded-md'),
    (r'\brounded-lg\b', 'rounded-md'),
    (r'\brounded-full\b', 'rounded-full'), # keep full for avatars
]

fixed_files = 0

for root, _, files in os.walk(directory):
    for file in files:
        if file.endswith('.tsx') or file.endswith('.ts'):
            filepath = os.path.join(root, file)
            with open(filepath, 'r') as f:
                content = f.read()
            
            new_content = content
            for pattern, repl in replacements:
                new_content = re.sub(pattern, repl, new_content)
            
            # fix empty class strings if any like className=""
            new_content = new_content.replace('className=" "', 'className=""')
            new_content = new_content.replace('className="  "', 'className=""')

            if new_content != content:
                with open(filepath, 'w') as f:
                    f.write(new_content)
                fixed_files += 1

print(f"Fixed {fixed_files} files.")
