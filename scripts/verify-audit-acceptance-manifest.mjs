import { readdirSync, readFileSync } from 'node:fs';
import { resolve, join, relative } from 'node:path';

const root = resolve(new URL('..', import.meta.url).pathname);
const manifest = JSON.parse(readFileSync(resolve(root, 'docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json'), 'utf8'));
const lifecycle = JSON.parse(readFileSync(resolve(root, 'docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json'), 'utf8'));
const errors = [];
if (manifest.schema_version !== 1 || !Array.isArray(manifest.gates)) errors.push('invalid manifest schema');

// finding_sources must name exactly the dated registers the lifecycle
// validator discovers. It was previously a single unvalidated string and went
// stale the moment a second audit landed.
const registers = readdirSync(resolve(root, 'docs'))
  .filter((name) => /^SYSTEM-AUDIT-FINDINGS-\d{4}-\d{2}-\d{2}\.md$/.test(name))
  .sort()
  .map((name) => `docs/${name}`);
const declared = Array.isArray(manifest.finding_sources) ? [...manifest.finding_sources].sort() : null;
if (declared === null) {
  errors.push('finding_sources must be an array of register paths');
} else if (declared.join('|') !== registers.join('|')) {
  errors.push(`finding_sources mismatch: declared ${declared.join(', ') || '(none)'}; found ${registers.join(', ')}`);
}
const ids = new Set();
for (const gate of manifest.gates ?? []) {
  if (!/^F-\d{3}$/.test(gate.id ?? '')) errors.push(`invalid gate id ${gate.id}`);
  if (ids.has(gate.id)) errors.push(`duplicate gate ${gate.id}`);
  ids.add(gate.id);
  if (!['focused_test', 'static_audit', 'operational', 'external_evidence', 'policy_decision', 'constraint_verification', 'ci_contract'].includes(gate.type)) errors.push(`${gate.id}: invalid gate type`);
  if (gate.id === 'F-030' && gate.type !== 'external_evidence') errors.push('F-030 must remain external_evidence');
  if (gate.id === 'F-030' && gate.command !== null) errors.push('F-030 cannot claim a local command pass');
  if (gate.id !== 'F-030' && (typeof gate.command !== 'string' || gate.command.trim() === '')) errors.push(`${gate.id}: missing machine gate command`);
}
for (const row of lifecycle) if (!ids.has(row.id)) errors.push(`${row.id}: missing acceptance gate`);
for (const id of ids) if (!lifecycle.some((row) => row.id === id)) errors.push(`${id}: gate has no lifecycle finding`);

// A focused_test gate whose --filter names nothing certifies nothing. Six of the
// 34 shipped filters were in that state: F-009, F-015 and F-037 matched no test
// at all, F-006 lost half of an alternation, and F-038 ran 22 unrelated tests
// (invoice VAT, a birthday calendar) while never touching the withholding
// brackets it certifies. They read as green for as long as they did because
// PHPUnit exits 0 on an empty selection unless failOnEmptyTestSuite is set —
// api/phpunit.xml sets it now, which turns a dead filter into a loud failure at
// run time. This turns it into a static one, so the drift is caught by the
// governance workflow instead of by whoever next runs a gate by hand.
//
// --filter is a case-insensitive regex over "Namespace\Class::method", so an
// alternative may legitimately name a directory-derived namespace segment, a
// class, or a single method. All three are collected; anything narrower produces
// false failures (`BIR` legitimately resolves through `BirAlphalistTest`).
const testsRoot = resolve(root, 'api/tests');
const collectTestNames = (dir, out = []) => {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) collectTestNames(path, out);
    else if (entry.name.endsWith('.php')) {
      out.push(relative(testsRoot, path).replace(/\.php$/, '').toLowerCase());
      for (const [, name] of readFileSync(path, 'utf8').matchAll(/function\s+(\w+)/g)) out.push(name.toLowerCase());
    }
  }
  return out;
};
const testNames = collectTestNames(testsRoot);
for (const gate of manifest.gates ?? []) {
  if (gate.type !== 'focused_test') continue;
  // The SPA gate runs the whole vitest suite and carries no --filter.
  const match = /--filter=('([^']*)'|"([^"]*)"|(\S+))/.exec(gate.command ?? '');
  if (!match) continue;
  for (const alternative of (match[2] ?? match[3] ?? match[4]).split('|')) {
    const needle = alternative.toLowerCase();
    if (!testNames.some((name) => name.includes(needle))) {
      errors.push(`${gate.id}: --filter '${alternative}' matches no test class, namespace or method`);
    }
  }
}
// Kept explicit by decision: growing the registry stays a deliberate,
// reviewable edit rather than one absorbed silently.
if (manifest.gates?.length !== 47) errors.push(`expected 47 gates, got ${manifest.gates?.length ?? 0}`);
if (errors.length) { console.error(errors.join('\n')); process.exit(1); }
console.log('Audit acceptance manifest clean: 47 findings mapped; F-030 remains external-evidence-only.');
