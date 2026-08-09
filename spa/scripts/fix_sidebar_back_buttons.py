import os
import re

def get_sidebar_routes():
    sidebar_path = 'src/components/layout/Sidebar.tsx'
    routes = []
    with open(sidebar_path, 'r', encoding='utf-8') as f:
        content = f.read()
        # Find all { to: '/something', ... }
        matches = re.finditer(r'\{\s*to:\s*\'([^\']+)\'', content)
        for m in matches:
            routes.append(m.group(1))
    return routes

def route_to_file_paths(route):
    # route could be '/dashboard' -> 'src/pages/dashboard.tsx' or 'src/pages/dashboard/index.tsx'
    base = route.strip('/')
    paths = [
        f"src/pages/{base}.tsx",
        f"src/pages/{base}/index.tsx"
    ]
    # Special cases
    if route == '/inventory/warehouse-map':
        paths.append('src/pages/inventory/warehouse/index.tsx')
    return paths

def fix_page(filepath):
    if not os.path.exists(filepath):
        return False
    
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Find <PageHeader ... />
    # We want to remove backTo="anything" and backLabel="anything"
    if '<PageHeader' in content and 'backTo=' in content:
        # Regex to match backTo={...} or backTo="..."
        content = re.sub(r'\s+backTo=(?:\{[^}]+\}|"[^"]+"|`[^`]+`)', '', content)
        # Regex to match backLabel="..."
        content = re.sub(r'\s+backLabel=(?:\{[^}]+\}|"[^"]+"|`[^`]+`)', '', content)
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        return True
    return False

if __name__ == "__main__":
    routes = get_sidebar_routes()
    fixed_count = 0
    for route in routes:
        paths = route_to_file_paths(route)
        for path in paths:
            if fix_page(path):
                print(f"Fixed {path} for route {route}")
                fixed_count += 1
                
    print(f"Total fixed: {fixed_count}")
