#!/usr/bin/env node
/**
 * Fails if any source file outside styles/tokens.css hard-codes a colour.
 *
 * The Atelier rebrand works because every colour decision resolves through a
 * token — that is the property that let 355 pages restyle without being
 * edited. This gate is what keeps it true.
 *
 * Run: npm run audit:tokens
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { relative, join } from 'node:path';

const PALETTE =
  /\b(?:bg|text|border|ring|from|to|via|divide|outline|shadow|fill|stroke|decoration|accent|caret)-(?:gray|slate|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/;
const HEX = /#[0-9a-fA-F]{6}\b/;

/**
 * Exemptions, each with a reason. Landing used to be exempt while it ran a
 * parallel --landing-* palette; it now resolves through the shared Atelier
 * tokens like every other page, so the gate covers it.
 */
const EXEMPT = [
  /^src\/styles\/tokens\.css$/, // the one place colour values belong
  /^src\/lib\/__tests__\/contrast\.test\.ts$/, // hex fixtures under test
  /^src\/styles\/__tests__\//, // reads tokens.css by design
  /^src\/pages\/landing\/three\//, // WebGL: three.js needs real colour values, not classes
  /^src\/pages\/landing\/components\/HeroCanvas\.tsx$/, // ditto — canvas materials
];

/**
 * Recursive walk, sorted for deterministic output.
 * Deliberately avoids fs.globSync (Node 22+) — CI pins Node 20.
 */
function walk(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(ts|tsx|css)$/.test(entry)) out.push(p);
  }
  return out;
}

const files = walk('src').sort();
const violations = [];

for (const file of files) {
  const rel = relative('.', file);
  if (EXEMPT.some((re) => re.test(rel))) continue;

  const lines = readFileSync(file, 'utf8').split('\n');
  lines.forEach((line, i) => {
    const trimmed = line.trimStart();
    if (trimmed.startsWith('//') || trimmed.startsWith('*')) return;
    const palette = PALETTE.exec(line);
    if (palette) violations.push(`${rel}:${i + 1}  raw palette class  ${palette[0]}`);
    const hex = HEX.exec(line);
    if (hex) violations.push(`${rel}:${i + 1}  hex literal  ${hex[0]}`);
  });
}

if (violations.length > 0) {
  console.error(`\n✗ ${violations.length} token-discipline violation(s):\n`);
  for (const v of violations) console.error(`  ${v}`);
  console.error(
    '\nUse a semantic token instead (bg-accent, text-danger, border-default).' +
      '\nColour values belong only in src/styles/tokens.css.\n',
  );
  process.exit(1);
}

console.log(`✓ token discipline clean — ${files.length} files checked`);
