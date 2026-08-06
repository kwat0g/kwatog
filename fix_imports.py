import os
import re

for root, _, files in os.walk('spa/src'):
    for file in files:
        if not file.endswith('.tsx'): continue
        path = os.path.join(root, file)
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        modified = False
        
        # Check if Link is used anywhere else than the import
        if 'import { Link' in content:
            # Check for <Link usages
            if '<Link' not in content:
                content = re.sub(r'import \{([^\}]*)Link([^\}]*)\}', lambda m: f"import {{{m.group(1).replace(',', '').strip()}{',' if m.group(1).strip() and m.group(2).strip() else ''} {m.group(2).replace(',', '').strip()}}}", content)
                content = content.replace('import { } from \'react-router-dom\';\n', '')
                content = content.replace('import {  } from \'react-router-dom\';\n', '')
                content = content.replace('import {} from \'react-router-dom\';\n', '')
                modified = True

        # Check if useNavigate is used
        if 'import { useNavigate' in content and 'useNavigate(' not in content:
            content = re.sub(r'import \{([^\}]*)useNavigate([^\}]*)\}', lambda m: f"import {{{m.group(1).replace(',', '').strip()}{',' if m.group(1).strip() and m.group(2).strip() else ''} {m.group(2).replace(',', '').strip()}}}", content)
            content = content.replace('import { } from \'react-router-dom\';\n', '')
            content = content.replace('import {  } from \'react-router-dom\';\n', '')
            content = content.replace('import {} from \'react-router-dom\';\n', '')
            modified = True

        # Check if cn is used
        if 'import { cn } from \'@/lib/cn\';' in content and 'cn(' not in content:
            content = content.replace("import { cn } from '@/lib/cn';\n", '')
            modified = True

        # Fix malformed imports
        content = re.sub(r'import\s*\{\s*,\s*', 'import { ', content)
        content = re.sub(r'\s*,\s*\}', ' }', content)
        
        if modified:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(content)
