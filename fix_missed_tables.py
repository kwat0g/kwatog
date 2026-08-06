import os
import re

def process_file(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    modified = False

    if '<DataTable' in content and 'columns' in content:
        if 'onRowClick=' not in content:
            columns_match = re.search(r'const\s+columns(?:[\s\w<\[\]>,:]+)?\s*=\s*\[(.*?)\];', content, re.DOTALL)
            if columns_match:
                cols_content = columns_match.group(1)
                if '<Link ' in cols_content:
                    link_match = re.search(r'<Link[^>]+to=\{?`([^`]+)`\}?[^>]*>', cols_content)
                    if not link_match:
                        link_match = re.search(r'<Link[^>]+to=\{([^}]+)\}[^>]*>', cols_content)
                        if not link_match:
                            link_match = re.search(r'<Link[^>]+to="([^"]+)"[^>]*>', cols_content)
                    
                    if link_match:
                        url_pattern = link_match.group(1)
                        
                        cell_match = re.search(r'cell:\s*\(([^)]+)\)\s*=>', cols_content)
                        row_var = 'r'
                        if cell_match:
                            row_var = cell_match.group(1).strip()
                            if ':' in row_var:
                                row_var = row_var.split(':')[0].strip()
                        
                        # Replace <Link ...> with <span ...>
                        def link_replacer(match):
                            tag = match.group(0)
                            # Extract className if present
                            class_match = re.search(r'className="([^"]+)"', tag)
                            if class_match:
                                classes = class_match.group(1).replace('hover:underline', '').strip()
                                return f'<span className="{classes}">'
                            return '<span>'

                        new_cols_content = re.sub(r'<Link[^>]*>', link_replacer, cols_content)
                        new_cols_content = new_cols_content.replace('</Link>', '</span>')
                        
                        content = content.replace(cols_content, new_cols_content)
                        
                        if '${' in url_pattern:
                            navigate_call = f"onRowClick={{({row_var}) => navigate(`{url_pattern}`)}}"
                        elif url_pattern.startswith('/'):
                            navigate_call = f"onRowClick={{({row_var}) => navigate('{url_pattern}')}}"
                        else:
                            navigate_call = f"onRowClick={{({row_var}) => navigate({url_pattern})}}"
                        
                        content = re.sub(r'(<DataTable\s+)', r'\1' + navigate_call + r'\n            ', content, count=1)
                        
                        # Ensure useNavigate
                        if 'useNavigate' not in content:
                            if 'import { Link ' in content:
                                content = content.replace('import { Link ', 'import { Link, useNavigate ')
                            elif 'react-router-dom' in content:
                                content = re.sub(r'import\s+\{([^}]+)\}\s+from\s+[\'"]react-router-dom[\'"];', r'import {\1, useNavigate} from "react-router-dom";', content)
                            else:
                                content = "import { useNavigate } from 'react-router-dom';\n" + content
                                
                        if 'const navigate = useNavigate()' not in content:
                            content = re.sub(r'(\s+const \w+\s*=\s*use)', r'\n  const navigate = useNavigate();\1', content, count=1)

                        modified = True

    if modified:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Updated {path}")

for root, _, files in os.walk('spa/src'):
    for file in files:
        if file.endswith('.tsx'):
            process_file(os.path.join(root, file))
