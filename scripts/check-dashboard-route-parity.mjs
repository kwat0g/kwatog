#!/usr/bin/env node
/**
 * Catalog ↔ route-guard parity (DSS 01-matrix §D3).
 *
 * `DashboardCatalog` is the single source of a dashboard's identity: its key,
 * its path and the permission that gates it. `spa/src/routes/dashboardRoutes.tsx`
 * repeats that permission as a `PermissionGuard`, which the catalog docblock
 * calls "defence in depth" — but until now nothing checked the two agreed.
 *
 * What a disagreement costs: `DashboardDispatchService` sends a role to
 * `/dashboard/<key>` because it holds the CATALOG permission, and the guard then
 * refuses it because it wants a DIFFERENT slug. The user lands on a permission
 * page for a dashboard they were dispatched to by the server — and the failure
 * is invisible to every existing test, because the backend suite has no access
 * to the SPA source and the SPA suite has no access to the PHP catalog.
 *
 * This script is the seam where both halves are visible, which is also why it is
 * a repo-level check rather than a test in either suite. Runs in CI alongside
 * `audit:tokens`.
 *
 * Exit 0 = every catalog entry has a route whose guard names the same
 * permission. Exit 1 = drift, with the offending entries printed.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '..');

const CATALOG = resolve(repoRoot, 'api/app/Modules/Dashboard/Support/DashboardCatalog.php');
const ROUTES = resolve(repoRoot, 'spa/src/routes/dashboardRoutes.tsx');

/** `['key' => 'x', 'path' => '/y', 'permission' => 'z', 'name' => '…'],` entries. */
function catalogEntries(source) {
  const rows = [...source.matchAll(/\[\s*'key'\s*=>\s*'([^']+)'\s*,\s*'path'\s*=>\s*'([^']+)'\s*,\s*'permission'\s*=>\s*'([^']+)'/g)];

  return rows.map(([, key, path, permission]) => ({ key, path, permission }));
}

/**
 * Each `<Route path="…" …>` block, so a permission is matched against the route
 * it is actually on. A whole-file `includes()` would happily accept a guard that
 * lives on a different route.
 */
function routeBlocks(source) {
  const blocks = [];
  const marker = /<Route\s+path="([^"]+)"/g;
  const starts = [...source.matchAll(marker)];

  starts.forEach((match, index) => {
    const from = match.index;
    const to = index + 1 < starts.length ? starts[index + 1].index : source.length;
    blocks.push({ path: match[1], body: source.slice(from, to) });
  });

  return blocks;
}

function main() {
  const entries = catalogEntries(readFileSync(CATALOG, 'utf8'));
  const blocks = routeBlocks(readFileSync(ROUTES, 'utf8'));

  if (entries.length === 0) {
    console.error('✗ parsed zero entries out of DashboardCatalog — the regex has gone stale, which is a failure of this check, not a clean result.');
    process.exit(1);
  }

  const problems = [];

  for (const { key, path, permission } of entries) {
    const block = blocks.find((candidate) => candidate.path === path);

    if (!block) {
      problems.push(`${key}: no <Route path="${path}"> in dashboardRoutes.tsx — the catalog dispatches there and nothing serves it.`);
      continue;
    }

    if (!block.body.includes(`permission="${permission}"`)) {
      const found = block.body.match(/permission="([^"]+)"/)?.[1] ?? 'no PermissionGuard';
      problems.push(`${key}: route guard is "${found}" but the catalog permission is "${permission}".`);
    }
  }

  if (problems.length > 0) {
    console.error(`✗ dashboard catalog ↔ route guard drift (${problems.length}):`);
    for (const problem of problems) console.error(`  · ${problem}`);
    process.exit(1);
  }

  console.log(`✓ dashboard catalog ↔ route guard parity — ${entries.length} dashboards checked, each route guarded by its own catalog permission.`);
}

main();
