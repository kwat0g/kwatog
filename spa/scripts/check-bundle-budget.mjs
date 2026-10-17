#!/usr/bin/env node
/**
 * Bundle budget — fail the build when the shipped JavaScript or CSS grows past
 * its budget.
 *
 * WHY IT EXISTS
 * "It builds" says nothing about what a user downloads. This app ships 579
 * chunks; one eager import in a shared component pulls a vendor library into the
 * entry path, and nobody notices until somebody's phone is slow. The budgets in
 * `bundle-budget.json` were measured, not guessed, and each one names the
 * mistake it is there to catch.
 *
 * WHAT IT MEASURES
 * Gzipped bytes of the artifact `npm run build` just produced — what the network
 * carries, not what sits on disk. `initialJs` follows `dist/index.html` to the
 * entry chunk and its modulepreloads, because that is exactly what a first-time
 * visitor fetches before anything paints; the other four are aggregate guards.
 *
 * HOW IT FAILS
 * Loudly, and in three cases, because a budget checker that exits 0 when it
 * cannot see the artifact is worse than no checker (this programme has already
 * recorded three probes that reported success while measuring the wrong thing —
 * C-001, C-002, C-003):
 *   - no build to measure        → exit 1, tell the caller to run `npm run build`
 *   - a metric with no budget    → exit 1, so adding a measurement forces a decision
 *   - a budget exceeded          → exit 1, with the table showing where
 *
 * Usage:
 *   npm run audit:bundle
 *   node scripts/check-bundle-budget.mjs --budget path/to/other.json --dist dist
 */
import { readFileSync, readdirSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import process from 'node:process';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const SPA_ROOT = resolve(SCRIPT_DIR, '..');

function arg(name, fallback) {
  const flag = `--${name}`;
  const eqForm = process.argv.find((a) => a.startsWith(`${flag}=`));
  if (eqForm) {
    throw new Error(`argument "${eqForm}" uses "=" — pass "${flag} ${eqForm.slice(flag.length + 1)}" instead`);
  }
  const index = process.argv.indexOf(flag);
  if (index === -1) return fallback;
  const value = process.argv[index + 1];
  if (value === undefined || value.startsWith('--')) {
    throw new Error(`argument "${flag}" is missing a value`);
  }
  return value;
}

const BUDGET_FILE = arg('budget', join(SCRIPT_DIR, 'bundle-budget.json'));
const DIST = resolve(SPA_ROOT, arg('dist', 'dist'));

/** Gzipped size of `dist`-relative asset path like `/assets/index-abc.js`. */
function gzipSizeOf(assetPath) {
  const file = join(DIST, assetPath.replace(/^\//, ''));
  return gzipSync(readFileSync(file)).length;
}

function measure() {
  const indexPath = join(DIST, 'index.html');
  let html;
  try {
    html = readFileSync(indexPath, 'utf8');
  } catch {
    console.error(
      `No build to measure: ${indexPath} does not exist.\n` +
        `Run \`npm run build\` first (the budget is enforced on its output), then re-run this audit.`,
    );
    process.exit(1);
  }

  const hrefs = (pattern) => [...html.matchAll(pattern)].map((match) => match[1]);
  const entry = hrefs(/<script[^>]+src="([^"]+)"/g);
  const preloads = hrefs(/<link[^>]+rel="modulepreload"[^>]+href="([^"]+)"/g);
  if (entry.length === 0) {
    console.error(`Found no entry script in ${indexPath} — the build output is not shaped as expected.`);
    process.exit(1);
  }

  const assetsDir = join(DIST, 'assets');
  const assets = readdirSync(assetsDir);
  const js = assets.filter((name) => name.endsWith('.js'));
  const css = assets.filter((name) => name.endsWith('.css'));

  const sum = (names, sizeOf) => names.reduce((total, name) => total + sizeOf(name), 0);
  const largest = js.reduce(
    (worst, name) => {
      const size = gzipSizeOf(`/assets/${name}`);
      return size > worst.size ? { name, size } : worst;
    },
    { name: '(none)', size: 0 },
  );

  return {
    initialJs: sum([...new Set([...entry, ...preloads])], gzipSizeOf),
    entryJs: sum(entry, gzipSizeOf),
    totalJs: sum(js, (name) => gzipSizeOf(`/assets/${name}`)),
    largestJs: largest.size,
    totalCss: sum(css, (name) => gzipSizeOf(`/assets/${name}`)),
    detail: { entry, preloads: preloads.length, jsCount: js.length, cssCount: css.length, largest: largest.name },
  };
}

const measured = measure();
const budgets = JSON.parse(readFileSync(BUDGET_FILE, 'utf8')).budgets;

const kb = (bytes) => bytes / 1024;
const fmt = (bytes) => `${kb(bytes).toFixed(1)} kB`;

const rows = Object.entries(measured).filter(([, value]) => typeof value === 'number');
const missing = rows.filter(([name]) => !budgets[name]).map(([name]) => name);

console.log(
  `Bundle budget — ${DIST}\n` +
    `  entry: ${measured.detail.entry.join(', ')} + ${measured.detail.preloads} modulepreload ` +
    `across ${measured.detail.jsCount} js / ${measured.detail.cssCount} css\n`,
);

let failed = false;
for (const [name, bytes] of rows) {
  const budget = budgets[name];
  if (!budget) continue;
  const over = bytes > budget.kb * 1024;
  failed ||= over;
  const headroom = ((budget.kb * 1024 - bytes) / (budget.kb * 1024)) * 100;
  console.log(
    `  ${over ? '✗' : '✓'} ${name.padEnd(10)} ${fmt(bytes).padStart(9)} / ${String(budget.kb).padStart(5)} kB budget` +
      `   ${over ? `OVER by ${fmt(bytes - budget.kb * 1024)}` : `${headroom.toFixed(0)}% headroom`}`,
  );
}

if (missing.length > 0) {
  console.error(
    `\n${missing.length} measured metric(s) have no budget: ${missing.join(', ')}.\n` +
      `Add them to ${BUDGET_FILE} with a reason — an unbudgeted measurement is a number nobody will act on.`,
  );
  process.exit(1);
}

if (failed) {
  console.error(
    `\nBundle budget exceeded. Either the growth is intended — raise the budget in ${BUDGET_FILE} with a reason —\n` +
      `or it is not, and something got pulled onto a path a user waits for. Largest chunk: ${measured.detail.largest}.`,
  );
  process.exit(1);
}

console.log(`\nAll ${rows.length} budgets hold.`);
