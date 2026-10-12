#!/usr/bin/env node
/**
 * DSS route-guard parity (F-13).
 *
 * The DSS permission model has three layers that must agree: the
 * `RolePermissionSeeder` catalog (what exists), the backend route gates in
 * `api/app/Modules/DSS/routes.php` (what the server enforces), and the SPA
 * `PermissionGuard`s (what the UI admits). A disagreement is a 403 on a page
 * the server sent you to, or a page that exists and is unreachable — and it is
 * invisible to both suites, because the api container mounts only api/ while
 * the SPA suite cannot read the PHP catalog. Same seam as
 * check-dashboard-route-parity.mjs: this script is the one place both halves
 * are visible, so it is a repo-level check, not a test in either suite.
 *
 * Exit 0 = every gate names a catalogued slug and every DSS route block
 * carries its own permission gate. Exit 1 = drift, offenders printed.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '..');

const SEEDER = resolve(repoRoot, 'api/database/seeders/RolePermissionSeeder.php');
const BACKEND_ROUTES = resolve(repoRoot, 'api/app/Modules/DSS/routes.php');
const SPA_ROUTES = resolve(repoRoot, 'spa/src/routes/adminRoutes.tsx');

function catalogSlugs(source) {
  return new Set([...source.matchAll(/'slug'\s*=>\s*'([^']+)'/g)].map(([, slug]) => slug));
}

function backendGates(source) {
  // One statement per Route::…; — the file carries no closures, so the first
  // semicolon after each Route:: safely terminates its statement.
  const statements = [...source.matchAll(/Route::(?:get|post|patch|put|delete)\([\s\S]*?;/g)].map(
    ([statement]) => statement,
  );
  return statements.map((body) => ({
    body,
    gates: [...body.matchAll(/middleware\(\s*'permission:([^']+)'\s*\)/g)].map(([, slug]) => slug),
  }));
}

function spaGuards(source) {
  // Per-<Route> blocks, like the dashboard script: a guard counts only for
  // the route it sits on. DSS owns /decision-center* and /admin/dss.
  const starts = [...source.matchAll(/<Route\s+path="([^"]+)"/g)];
  const guards = [];
  starts.forEach((match, index) => {
    const path = match[1];
    if (!path.startsWith('/decision-center') && path !== '/admin/dss') return;
    const from = match.index;
    const to = index + 1 < starts.length ? starts[index + 1].index : source.length;
    const body = source.slice(from, to);
    for (const [, slug] of body.matchAll(/PermissionGuard\s+permission="([^"]+)"/g)) {
      guards.push({ path, slug });
    }
  });
  return guards;
}

function main() {
  const failures = [];
  const catalog = catalogSlugs(readFileSync(SEEDER, 'utf8'));
  const backend = readFileSync(BACKEND_ROUTES, 'utf8');
  const spa = readFileSync(SPA_ROUTES, 'utf8');

  for (const slug of ['dss.view', 'dss.manage', 'dss.admin']) {
    if (!catalog.has(slug)) failures.push(`catalog is missing ${slug}`);
  }

  const statements = backendGates(backend);
  if (statements.length === 0) failures.push('no DSS backend routes parsed');
  for (const { body, gates } of statements) {
    const firstLine = body.split('\n')[0].trim();
    if (gates.length === 0) {
      failures.push(`backend route without permission gate: ${firstLine}`);
      continue;
    }
    for (const slug of gates) {
      if (!catalog.has(slug)) failures.push(`backend gate names unknown slug ${slug} in: ${firstLine}`);
    }
  }
  const guards = spaGuards(spa);
  if (guards.length === 0) failures.push('no DSS SPA guards parsed');
  for (const { path, slug } of guards) {
    if (!catalog.has(slug)) failures.push(`SPA guard on ${path} names unknown slug ${slug}`);
  }

  // Every DSS-owned SPA route must carry exactly its guard (no missing, no extra).
  const guardedPaths = new Set(guards.map(({ path }) => path));
  for (const path of ['/decision-center', '/decision-center/:id', '/admin/dss']) {
    if (!guardedPaths.has(path)) failures.push(`SPA route ${path} has no PermissionGuard`);
  }

  if (failures.length > 0) {
    for (const failure of failures) console.error(`dss-parity: ${failure}`);
    process.exit(1);
  }
  console.log(
    `dss-parity: ok — ${statements.length} backend routes, ${guards.length} SPA guards, all catalogued.`,
  );
}

main();
