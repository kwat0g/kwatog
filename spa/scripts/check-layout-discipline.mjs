#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim();
const ROUTE_INVENTORY = join(REPO_ROOT, 'docs/layout/route-inventory.csv');
const ARBITRARY_SIZE_CLASS = /(?:^|[\s"'`])(?:[a-z0-9-]+:)*(?:-?(?:p[trblxy]?|m[trblxy]?|gap(?:-[xy])?|space-[xy]|inset(?:-[xy])?|top|right|bottom|left|w|min-w|max-w|h|min-h|max-h|size|basis|grid-cols|grid-rows|leading|tracking|rounded|text))-\[[^\]]+\]/g;
const RAW_LAYOUT_VALUE = /\b(?:margin(?:-(?:block|inline|top|right|bottom|left))?|padding(?:-(?:block|inline|top|right|bottom|left))?|gap|row-gap|column-gap|font-size|width|height|min-width|max-width|min-height|max-height)\s*:\s*-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em|vw|vh|ch)\b/gi;
const RAW_INLINE_VALUE = /\b(?:margin(?:Top|Right|Bottom|Left|Inline|Block)?|padding(?:Top|Right|Bottom|Left|Inline|Block)?|gap|fontSize|width|height|minWidth|maxWidth|minHeight|maxHeight)\s*:\s*-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em)?(?=\s*[,}])/g;
const BREAKPOINTS = new Set(['640px', '768px', '1024px', '1200px', '1280px', '1400px', '1440px', '1920px']);

export function addedLinesByFile(diff) {
  const files = new Map();
  let currentFile = '';
  for (const line of diff.split('\n')) {
    if (line.startsWith('+++ b/')) {
      currentFile = line.slice(6);
      if (!files.has(currentFile)) files.set(currentFile, []);
    } else if (currentFile && line.startsWith('+') && !line.startsWith('+++')) {
      files.get(currentFile).push(line.slice(1));
    }
  }
  return files;
}

export function inspectAddedLines(file, lines, routeSources = new Set()) {
  if (file === 'spa/src/styles/tokens.css' || /\.(?:test|spec)\.[cm]?[jt]sx?$/.test(file)) return [];

  const violations = [];
  for (const line of lines) {
    const trimmed = line.trimStart();
    if (trimmed.startsWith('//') || trimmed.startsWith('*') || trimmed.startsWith('/*')) continue;

    for (const match of line.matchAll(new RegExp(ARBITRARY_SIZE_CLASS))) {
      violations.push(`${file}: arbitrary spacing/type/size utility ${match[0].trim()}`);
    }

    if (/\.(?:css|scss)$/.test(file)) {
      const breakpoint = trimmed.match(/^@media\s+\(min-width:\s*(\d+(?:\.\d+)?px)\)/i)?.[1];
      for (const match of line.matchAll(new RegExp(RAW_LAYOUT_VALUE))) {
        if (match[0].startsWith('min-width:') && breakpoint && BREAKPOINTS.has(breakpoint)) continue;
        violations.push(`${file}: raw layout/type value ${match[0]}`);
      }
    }

    if (/\.tsx?$/.test(file) && /style\s*=\s*\{\{/.test(line)) {
      for (const match of line.matchAll(new RegExp(RAW_INLINE_VALUE))) {
        violations.push(`${file}: raw inline layout/type value ${match[0]}`);
      }
    }

    if (routeSources.has(file)) {
      for (const [, classes] of line.matchAll(/className\s*=\s*["'`]([^"'`]+)["'`]/g)) {
        const names = classes.split(/\s+/);
        if (names.includes('mx-auto') && names.some((name) => name.startsWith('max-w-'))) {
          violations.push(`${file}: centered max-width page wrapper; use PageShell/PageContainer narrow variant`);
        }
      }
    }
  }
  return violations;
}

export function inspectPageShell(file, contents, routeSources) {
  if (!routeSources.has(file)) return null;
  if (/<(?:PageShell|PageContainer)\b/.test(contents)) return null;
  if (/layout-exception:\s*\S/.test(contents)) return null;
  return `${file}: route page needs PageShell/PageContainer or a documented layout-exception`;
}

function routeSourcesFromInventory() {
  return new Set(
    readFileSync(ROUTE_INVENTORY, 'utf8')
      .split('\n')
      .map((line) => line.match(/,"(spa\/src\/pages\/[^\"]+)"(?:,|$)/)?.[1])
      .filter(Boolean),
  );
}

function run() {
  const base = process.env.LAYOUT_AUDIT_BASE_SHA;
  const diff = execFileSync(
    'git',
    ['diff', '--unified=0', base || 'HEAD', '--', 'spa/src'],
    { cwd: REPO_ROOT, encoding: 'utf8' },
  );
  const routeSources = routeSourcesFromInventory();
  const violations = [];
  const changedFiles = execFileSync(
    'git',
    ['diff', '--name-only', base || 'HEAD', '--', 'spa/src'],
    { cwd: REPO_ROOT, encoding: 'utf8' },
  ).split('\n').filter(Boolean);

  for (const [file, lines] of addedLinesByFile(diff)) {
    violations.push(...inspectAddedLines(file, lines, routeSources));
  }

  for (const file of changedFiles) {
    if (!routeSources.has(file)) continue;
    const contents = readFileSync(join(REPO_ROOT, file), 'utf8');
    const pageViolation = inspectPageShell(file, contents, routeSources);
    if (pageViolation) violations.push(pageViolation);
  }

  if (violations.length) {
    console.error(`\n✗ ${violations.length} layout-discipline violation(s):\n`);
    for (const violation of violations) console.error(`  ${violation}`);
    console.error('\nUse the spacing/type tokens and shared page layouts; document genuine special-layout exceptions.\n');
    process.exit(1);
  }

  console.log(`✓ layout discipline clean — ${addedLinesByFile(diff).size} changed source files checked`);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) run();
