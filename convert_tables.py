import re
import os

files = [
'spa/src/components/dashboard/FinanceSection.tsx',
'spa/src/components/dashboard/RoleDashboard.tsx',
'spa/src/components/dashboard/StockOutPanel.tsx',
'spa/src/pages/accounting/bills/index.tsx',
'spa/src/pages/accounting/coa/index.tsx',
'spa/src/pages/accounting/credit-notes/index.tsx',
'spa/src/pages/accounting/customers/detail.tsx',
'spa/src/pages/accounting/customers/index.tsx',
'spa/src/pages/accounting/invoices/index.tsx',
'spa/src/pages/accounting/journal-entries/index.tsx',
'spa/src/pages/accounting/vendors/detail.tsx',
'spa/src/pages/accounting/vendors/index.tsx',
'spa/src/pages/admin/users/index.tsx',
'spa/src/pages/admin/users-roles.tsx',
'spa/src/pages/assets/index.tsx',
'spa/src/pages/budgeting/departments.tsx',
'spa/src/pages/budgeting/detail.tsx',
'spa/src/pages/budgeting/index.tsx',
'spa/src/pages/crm/commissions/index.tsx',
'spa/src/pages/crm/complaints/index.tsx',
'spa/src/pages/crm/customers/detail.tsx',
'spa/src/pages/crm/customers/index.tsx',
'spa/src/pages/crm/products/index.tsx',
'spa/src/pages/crm/sales-orders/index.tsx',
'spa/src/pages/dashboard/finance.tsx',
'spa/src/pages/dashboard/plant-manager.tsx',
'spa/src/pages/forecasting/stock-out.tsx',
'spa/src/pages/hr/employees/detail.tsx',
'spa/src/pages/hr/recruitment/index.tsx',
'spa/src/pages/hr/separations/index.tsx',
'spa/src/pages/inventory/dashboard.tsx',
'spa/src/pages/inventory/grn/index.tsx',
'spa/src/pages/inventory/items/index.tsx',
'spa/src/pages/inventory/items/stock-card.tsx',
'spa/src/pages/inventory/material-issues/index.tsx',
'spa/src/pages/inventory/mrb/index.tsx',
'spa/src/pages/inventory/stock-levels/index.tsx',
'spa/src/pages/leaves/index.tsx',
'spa/src/pages/loans/index.tsx',
'spa/src/pages/maintenance/work-orders/index.tsx',
'spa/src/pages/mrp/boms/index.tsx',
'spa/src/pages/mrp/machines/index.tsx',
'spa/src/pages/mrp/molds/detail.tsx',
'spa/src/pages/mrp/molds/index.tsx',
'spa/src/pages/mrp/plans/detail.tsx',
'spa/src/pages/mrp/plans/index.tsx',
'spa/src/pages/payroll/periods/detail.tsx',
'spa/src/pages/payroll/periods/employee-detail.tsx',
'spa/src/pages/portal/customer/dashboard.tsx',
'spa/src/pages/portal/customer/deliveries/index.tsx',
'spa/src/pages/portal/customer/invoices/index.tsx',
'spa/src/pages/portal/customer/orders/index.tsx',
'spa/src/pages/portal/supplier/dashboard.tsx',
'spa/src/pages/portal/supplier/invoices/index.tsx',
'spa/src/pages/portal/supplier/purchase-orders/index.tsx',
'spa/src/pages/production/dashboard.tsx',
'spa/src/pages/production/oee.tsx',
'spa/src/pages/production/routings/index.tsx',
'spa/src/pages/production/work-orders/detail.tsx',
'spa/src/pages/production/work-orders/index.tsx',
'spa/src/pages/purchasing/purchase-orders/detail.tsx',
'spa/src/pages/purchasing/purchase-orders/index.tsx',
'spa/src/pages/purchasing/purchase-requests/index.tsx',
'spa/src/pages/quality/documents/index.tsx',
'spa/src/pages/quality/inspection-specs/index.tsx',
'spa/src/pages/quality/inspections/detail.tsx',
'spa/src/pages/quality/inspections/index.tsx',
'spa/src/pages/quality/ncrs/index.tsx',
'spa/src/pages/quality/spc/index.tsx',
'spa/src/pages/return-management/detail.tsx',
'spa/src/pages/return-management/list.tsx',
'spa/src/pages/supply-chain/deliveries/detail.tsx',
'spa/src/pages/supply-chain/deliveries/index.tsx'
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
    
    # inject const navigate = useNavigate(); inside the main function component
    # We will look for "export default function " or "export function "
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
    
    # For DataTable columns
    # We look for cell: (r) => <Link to={`/path/${r.id}`} className="...">content</Link>
    # and extract the `to` attribute for onRowClick
    
    # find DataTables usage
    dt_link_pattern = re.compile(r'cell:\s*\(([^)]+)\)\s*=>\s*<Link to=\{?(`[^`]+`|\'/[^\']+\'|"[^"]+")\}?\s+className="([^"]+)"(?:.*?)[>](.*?)</Link>')
    
    # We need to collect the row clicks for each DataTable. 
    # But wait, there could be multiple columns with Links? 
    # Usually the first column has the link.
    
    # Let's just find the first Link in columns that corresponds to a primary id.
    # Actually, a simpler way: just regex replace the <Link> with a <span> or just the content, 
    # and find the `to=` to add `onRowClick` to `<DataTable`
    
    # Let's extract paths manually
    links_found = dt_link_pattern.findall(content)
    if links_found:
        for link_info in links_found:
            r_param, to_path, class_name, link_inner = link_info
            
            # replace the Link with a span if class_name has font-mono or something, or just use the inner text if no styling needed.
            # actually we can just keep the font-mono part of the class name
            classes = [c for c in class_name.split() if c not in ('text-accent', 'hover:underline', 'text-primary', 'font-medium')]
            span_cls = ' '.join(classes)
            if span_cls:
                replacement = f'cell: ({r_param}) => <span className="{span_cls}">{link_inner}</span>'
            else:
                replacement = f'cell: ({r_param}) => {link_inner}'
            
            # replace the cell function
            old_cell_str = f'cell: ({r_param}) => <Link to={{{to_path}}}' if to_path.startswith('`') else f'cell: ({r_param}) => <Link to={to_path}'
            # to be safe, replace the full exact string:
            pattern_to_replace = re.compile(rf'cell:\s*\({re.escape(r_param)}\)\s*=>\s*<Link to={{?{re.escape(to_path)}}}?\s+className="{re.escape(class_name)}"[^>]*>{re.escape(link_inner)}</Link>')
            new_content = pattern_to_replace.sub(replacement, new_content)
            
            # Add onRowClick to DataTable
            # Look for <DataTable
            if f'onRowClick={{({r_param}) => navigate({to_path})}}' not in new_content:
                new_content = re.sub(r'<DataTable', f'<DataTable\n            onRowClick={{({r_param}) => navigate({to_path})}}', new_content, count=1)
            
            modified = True

    # For plain tables <tr> ... <Td><Link...
    # Pattern: <tr ...> <Td><Link to={`/path/${c.id}`} className="...">name</Link></Td>
    tr_pattern = re.compile(r'(<tr[^>]*key=\{([^}]+)\}[^>]*)>(.*?<Link to=\{?(`[^`]+`|\'/[^\']+\'|"[^"]+")\}?\s+className="([^"]+)"[^>]*>(.*?)</Link>.*?)</tr>', re.DOTALL)
    
    def repl_tr(match):
        tr_open = match.group(1)
        r_param_raw = match.group(2) # usually something like c.id, we can extract `c`
        to_path = match.group(4)
        class_name = match.group(5)
        link_inner = match.group(6)
        
        # update tr_open to add onClick and classes
        # if it already has className=...
        if 'className={' in tr_open:
            tr_open = re.sub(r'className=\{([^}]+)\}', r'className={cn(\1, "cursor-pointer")}', tr_open)
        elif 'className="' in tr_open:
            tr_open = re.sub(r'className="([^"]+)"', r'className="\1 cursor-pointer hover:bg-[var(--bg-row-hover)]"', tr_open)
        else:
            tr_open += ' className="cursor-pointer hover:bg-[var(--bg-row-hover)]"'
            
        tr_open += f' onClick={{() => navigate({to_path})}}'
        
        # replace the <Link> inside with just link_inner or <span>
        inner_content = match.group(3)
        link_str = f'<Link to={to_path} className="{class_name}">{link_inner}</Link>' if not to_path.startswith('`') else f'<Link to={{{to_path}}} className="{class_name}">{link_inner}</Link>'
        
        # fallback regex if exact replace fails
        inner_content = re.sub(r'<Link to=[^>]+>[^<]+</Link>', link_inner, inner_content)
        
        return f'{tr_open}>{inner_content}</tr>'

    if '<tr' in new_content and '<Link' in new_content:
        new_content_after_tr, count = tr_pattern.subn(repl_tr, new_content)
        if count > 0:
            new_content = new_content_after_tr
            # Ensure `cn` is imported if we added `cn(trCls, ...)`
            if 'cn(' in new_content and 'import { cn }' not in new_content:
                new_content = "import { cn } from '@/lib/cn';\n" + new_content
            modified = True

    if modified:
        new_content = add_use_navigate(new_content)
        with open(fpath, 'w', encoding='utf-8') as f:
            f.write(new_content)
            
