import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

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
// Kept explicit by decision: growing the registry stays a deliberate,
// reviewable edit rather than one absorbed silently.
if (manifest.gates?.length !== 42) errors.push(`expected 42 gates, got ${manifest.gates?.length ?? 0}`);
if (errors.length) { console.error(errors.join('\n')); process.exit(1); }
console.log('Audit acceptance manifest clean: 42 findings mapped; F-030 remains external-evidence-only.');
