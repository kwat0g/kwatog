import os
import glob
import re

# 1. leaves/year-end.tsx
f = 'src/pages/leaves/year-end.tsx'
if os.path.exists(f):
    with open(f, 'r') as file:
        content = file.read()
    content = content.replace('backTo="/hr/leaves" backLabel="Leaves"', '')
    with open(f, 'w') as file:
        file.write(content)

# 2. payroll/periods/index.tsx
f = 'src/pages/payroll/periods/index.tsx'
if os.path.exists(f):
    with open(f, 'r') as file:
        content = file.read()
    content = content.replace('variant="secondary" size="sm" icon={<CalendarRange size={14} />}', 'variant="primary" size="sm" icon={<CalendarRange size={14} />}')
    with open(f, 'w') as file:
        file.write(content)

# 3. Button.tsx
f = 'src/components/ui/Button.tsx'
if os.path.exists(f):
    with open(f, 'r') as file:
        content = file.read()
    if 'type Size = \'xs\' |' not in content:
        content = content.replace("type Size = 'sm' | 'md' | 'lg';", "type Size = 'xs' | 'sm' | 'md' | 'lg';")
        content = content.replace("const sizeClasses: Record<Size, string> = {\n  sm: 'h-7", "const sizeClasses: Record<Size, string> = {\n  xs: 'h-6 px-2 text-[11px]',\n  sm: 'h-7")
        content = content.replace("const iconOnlySizeClasses: Record<Size, string> = {\n  sm: 'h-7", "const iconOnlySizeClasses: Record<Size, string> = {\n  xs: 'h-6 w-6 text-[11px] rounded',\n  sm: 'h-7")
        content = content.replace("size === 'sm' || size === 'md' ? 'sm' : 'md'", "size === 'sm' || size === 'xs' || size === 'md' ? 'sm' : 'md'")
        with open(f, 'w') as file:
            file.write(content)

# 4. Inline sizes
inline_files = [
    'src/pages/hr/salary-adjustments/index.tsx',
    'src/pages/attendance/overtime/index.tsx',
    'src/pages/leaves/index.tsx',
    'src/pages/payroll/adjustments/index.tsx'
]
for f in inline_files:
    if os.path.exists(f):
        with open(f, 'r') as file:
            content = file.read()
        
        if 'salary-adjustments' in f:
            content = content.replace('variant="danger"\n  size="sm"', 'variant="danger"\n  size="xs"')
            content = content.replace('variant="primary"\n  size="sm"', 'variant="primary"\n  size="xs"')
        elif 'overtime' in f:
            content = content.replace('variant="primary" size="sm" onClick={() => { setConfirmApprove(r.id); }}', 'variant="primary" size="xs" onClick={() => { setConfirmApprove(r.id); }}')
            content = content.replace('variant="danger" size="sm" onClick={() => { setReject(r); }}', 'variant="danger" size="xs" onClick={() => { setReject(r); }}')
            content = content.replace('variant="primary" size="sm" disabled={approving}', 'variant="primary" size="xs" disabled={approving}')
            content = content.replace('variant="danger" size="sm" onClick={() => onReject?.(o)}', 'variant="danger" size="xs" onClick={() => onReject?.(o)}')
        elif 'leaves' in f:
            content = content.replace('variant="primary" size="sm" disabled={approveDept.isPending}', 'variant="primary" size="xs" disabled={approveDept.isPending}')
            content = content.replace('variant="danger" size="sm" onClick={() => { setActionTarget({ req: r, mode: \'reject\' }); }}', 'variant="danger" size="xs" onClick={() => { setActionTarget({ req: r, mode: \'reject\' }); }}')
            content = content.replace('variant="primary" size="sm" disabled={approveHR.isPending}', 'variant="primary" size="xs" disabled={approveHR.isPending}')
        elif 'payroll/adjustments' in f:
            content = content.replace('size="sm" variant="ghost" icon={<Check', 'size="xs" variant="ghost" icon={<Check')
            content = content.replace('size="sm" variant="ghost" icon={<X', 'size="xs" variant="ghost" icon={<X')

        with open(f, 'w') as file:
            file.write(content)
