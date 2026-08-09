import os
import re

def get_sidebar_routes():
    sidebar_path = 'src/components/layout/Sidebar.tsx'
    routes = []
    with open(sidebar_path, 'r', encoding='utf-8') as f:
        content = f.read()
        matches = re.finditer(r'\{\s*to:\s*\'([^\']+)\'', content)
        for m in matches:
            routes.append(m.group(1))
    return routes

def route_to_file_paths(route):
    base = route.strip('/')
    paths = [
        f"src/pages/{base}.tsx",
        f"src/pages/{base}/index.tsx"
    ]
    if route == '/inventory/warehouse-map':
        paths.append('src/pages/inventory/warehouse/index.tsx')
    if route == '/hr/attendance':
        paths.append('src/pages/attendance/index.tsx')
    if route == '/hr/attendance/overtime':
        paths.append('src/pages/attendance/overtime/index.tsx')
    if route == '/hr/leaves':
        paths.append('src/pages/leaves/index.tsx')
    if route == '/hr/leaves/year-end':
        paths.append('src/pages/leaves/year-end.tsx')
    return paths

routes = get_sidebar_routes()
found_any = False
for route in routes:
    paths = route_to_file_paths(route)
    for path in paths:
        if os.path.exists(path):
            with open(path, 'r', encoding='utf-8') as f:
                content = f.read()
                if '<PageHeader' in content and 'backTo=' in content:
                    print(f"WARNING: {path} (route {route}) still has backTo!")
                    found_any = True

if not found_any:
    print("All good, no sidebar pages have backTo!")
