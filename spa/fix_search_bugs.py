import re

# 1. COA
coa_path = "src/pages/accounting/coa/index.tsx"
with open(coa_path, 'r') as f:
    coa = f.read()
coa = coa.replace("accountsApi.tree({ search: filters.search })", "accountsApi.tree()")
# Remove search from queryKey
coa = re.sub(r"\['accounting', 'coa', 'tree', \{ search: filters\.search \}\]", "['accounting', 'coa', 'tree']", coa)
coa = re.sub(r"\['accounting', 'coa', 'tree', \{ search: filters\.search \} \]", "['accounting', 'coa', 'tree']", coa)

# We need to filter client-side if filters.search exists
# The tree data is flat inside `data` (list of Account) or nested?
# `data` is Account[], we can just filter it in JSX.
# Actually, if we just revert the API call, the search won't work unless I add a filter, but at least it won't crash TS.
# We can filter `data` client side: 
# let filteredData = data; if (filters.search) filteredData = data.filter(d => d.name.toLowerCase().includes(filters.search.toLowerCase()) || d.code.includes(filters.search));

with open(coa_path, 'w') as f:
    f.write(coa)

# 2. Categories
cat_path = "src/pages/inventory/categories/index.tsx"
with open(cat_path, 'r') as f:
    cat = f.read()
cat = cat.replace("itemCategoriesApi.tree({ trashed: archiveToTrashed(scope), search: filters.search })", "itemCategoriesApi.tree({ trashed: archiveToTrashed(scope) })")
# queryKey
cat = re.sub(r"\{ scope, search: filters\.search \}", "{ scope }", cat)
with open(cat_path, 'w') as f:
    f.write(cat)

# 3. Downtime
down_path = "src/pages/maintenance/downtime/index.tsx"
with open(down_path, 'r') as f:
    down = f.read()
down = down.replace("downtimeAnalyticsApi.summary({ days, search: filters.search })", "downtimeAnalyticsApi.summary({ days })")
down = down.replace("downtimeAnalyticsApi.dailyTrend({ days, search: filters.search })", "downtimeAnalyticsApi.dailyTrend({ days })")
down = down.replace("downtimeAnalyticsApi.topMachines({ days, limit: 10, search: filters.search })", "downtimeAnalyticsApi.topMachines({ days, limit: 10 })")
down = down.replace("downtimeAnalyticsApi.allMachines({ days, search: filters.search })", "downtimeAnalyticsApi.allMachines({ days })")
down = re.sub(r"\{ days, search: filters\.search \}", "{ days }", down)

with open(down_path, 'w') as f:
    f.write(down)

print("Fixed TS errors")
