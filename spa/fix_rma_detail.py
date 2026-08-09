import re

with open("src/pages/return-management/detail.tsx", "r") as f:
    content = f.read()

# 1. PageHeader modifications
page_header_pattern = r'<PageHeader\s+title=\{([^}]*?)\}\s+subtitle=\{([\s\S]*?)\}\s+backTo="([^"]*)"\s+breadcrumbs=\{([^}]*?)\}\s*/>'

# Let's just find the whole <PageHeader ... /> and replace it manually.
# It spans lines 249-262.
new_page_header = """<PageHeader
 title={
 <div className="flex items-center gap-3">
 <span className="font-mono">{rma.rma_number}</span>
 <Chip variant={STATUS_VARIANT[rma.status] ?? 'neutral'}>{rma.status_label}</Chip>
 </div>
 }
 subtitle={rma.type_label}
 backTo="/return-management"
 backLabel="Return Management"
 actions={
 <div className="flex gap-1.5">
 {actions.map((action) => (
 <Button
 key={action.key}
 size="sm"
 variant={action.variant === 'danger' ? 'danger' : 'primary'}
 onClick={() => handleAction(action.key)}
 >
 {action.label}
 </Button>
 ))}
 </div>
 }
 />"""

content = re.sub(r'<PageHeader[\s\S]*?/>', new_page_header, content, count=1)

# 2. Remove the old actions div
actions_div_pattern = r'\{/\* Workflow Actions \*/\}[\s\S]*?\{/\* Details Panel \*/\}'
content = re.sub(actions_div_pattern, '{/* Details Panel */}', content)

# 3. Add the grid layout wrapper
content = content.replace('<div className="px-4 space-y-4">', '<div className="px-5 py-4 space-y-4">\n <div className="grid gap-4 lg:grid-cols-3">\n <div className="lg:col-span-2 space-y-4">')

# 4. Find the Timeline Panel and wrap the right column
timeline_panel_start = content.find('{/* Timeline */}')
if timeline_panel_start != -1:
    content = content[:timeline_panel_start] + '</div>\n <div className="space-y-4">\n ' + content[timeline_panel_start:]

# 5. Find the Items Panel and move it to the left column
items_panel_start = content.find('{/* Items */}')
if items_panel_start != -1:
    items_panel_end = content.find('</Panel>', items_panel_start) + len('</Panel>')
    items_panel_str = content[items_panel_start:items_panel_end]
    content = content[:items_panel_start] + content[items_panel_end:]
    
    # insert items_panel before the end of the left column (which is before <div className="space-y-4"> for the right column)
    split_target = '</div>\n <div className="space-y-4">\n {/* Timeline */}'
    content = content.replace(split_target, items_panel_str + '\n ' + split_target)

# 6. Close the grid layout div
last_panel_end = content.find('{/* Confirm dialogs')
if last_panel_end != -1:
    content = content[:last_panel_end] + '</div>\n </div>\n\n ' + content[last_panel_end:]


with open("src/pages/return-management/detail.tsx", "w") as f:
    f.write(content)

