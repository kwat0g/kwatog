import re
import os

files = [
'spa/src/pages/admin/users-roles.tsx',
'spa/src/pages/dashboard/finance.tsx',
'spa/src/pages/portal/customer/dashboard.tsx',
'spa/src/pages/portal/supplier/dashboard.tsx',
'spa/src/pages/supply-chain/deliveries/detail.tsx'
]

def add_use_navigate(content):
    if "const navigate = useNavigate()" in content or "const nav = useNavigate()" in content:
        return content
    # add import if not present
    if "import { useNavigate" not in content and "import { Link, useNavigate" not in content:
        if "import { Link" in content and "import { LinkButton" not in content and "import { LinkedRecords" not in content:
            content = content.replace("import { Link", "import { Link, useNavigate")
        elif "react-router-dom" in content:
            content = re.sub(r'(import\s*\{[^}]*)(\}\s*from\s*[\'"]react-router-dom[\'"])', r'\1, useNavigate\2', content)
        else:
            # find first import
            content = "import { useNavigate } from 'react-router-dom';\n" + content
    
    match = re.search(r'export (?:default )?function\s+\w+\([^)]*\)\s*\{', content)
    if match:
        insert_pos = match.end()
        content = content[:insert_pos] + "\n  const navigate = useNavigate();" + content[insert_pos:]
    return content

for fpath in files:
    if not os.path.exists(fpath):
        continue
    with open(fpath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    modified = False
    new_content = content
    
    dt_link_pattern = re.compile(r'cell:\s*\(([^)]+)\)\s*=>\s*<Link to=\{?(`[^`]+`|\'/[^\']+\'|"[^"]+")\}?\s+className="([^"]+)"(?:.*?)[>](.*?)</Link>')
    
    links_found = dt_link_pattern.findall(content)
    if links_found:
        for link_info in links_found:
            r_param, to_path, class_name, link_inner = link_info
            
            classes = [c for c in class_name.split() if c not in ('text-accent', 'hover:underline', 'text-primary', 'font-medium')]
            span_cls = ' '.join(classes)
            if span_cls:
                replacement = f'cell: ({r_param}) => <span className="{span_cls}">{link_inner}</span>'
            else:
                replacement = f'cell: ({r_param}) => {link_inner}'
            
            pattern_to_replace = re.compile(rf'cell:\s*\({re.escape(r_param)}\)\s*=>\s*<Link to={{?{re.escape(to_path)}}}?\s+className="{re.escape(class_name)}"[^>]*>{re.escape(link_inner)}</Link>')
            new_content = pattern_to_replace.sub(replacement, new_content)
            
            if f'onRowClick={{({r_param}) => navigate({to_path})}}' not in new_content:
                new_content = re.sub(r'<DataTable', f'<DataTable\n            onRowClick={{({r_param}) => navigate({to_path})}}', new_content, count=1)
            
            modified = True

    tr_pattern = re.compile(r'(<tr[^>]*key=\{([^}]+)\}[^>]*)>(.*?<Link to=\{?(`[^`]+`|\'/[^\']+\'|"[^"]+")\}?\s+className="([^"]+)"[^>]*>(.*?)</Link>.*?)</tr>', re.DOTALL)
    
    def repl_tr(match):
        tr_open = match.group(1)
        r_param_raw = match.group(2)
        to_path = match.group(4)
        class_name = match.group(5)
        link_inner = match.group(6)
        
        if 'className={' in tr_open:
            tr_open = re.sub(r'className=\{([^}]+)\}', r'className={cn(\1, "cursor-pointer")}', tr_open)
        elif 'className="' in tr_open:
            tr_open = re.sub(r'className="([^"]+)"', r'className="\1 cursor-pointer hover:bg-[var(--bg-row-hover)]"', tr_open)
        else:
            tr_open += ' className="cursor-pointer hover:bg-[var(--bg-row-hover)]"'
            
        tr_open += f' onClick={{() => navigate({to_path})}}'
        
        inner_content = match.group(3)
        inner_content = re.sub(r'<Link to=[^>]+>[^<]+</Link>', link_inner, inner_content)
        
        return f'{tr_open}>{inner_content}</tr>'

    if '<tr' in new_content and '<Link' in new_content:
        new_content_after_tr, count = tr_pattern.subn(repl_tr, new_content)
        if count > 0:
            new_content = new_content_after_tr
            if 'cn(' in new_content and 'import { cn }' not in new_content:
                new_content = "import { cn } from '@/lib/cn';\n" + new_content
            modified = True

    if modified:
        new_content = add_use_navigate(new_content)
        with open(fpath, 'w', encoding='utf-8') as f:
            f.write(new_content)
