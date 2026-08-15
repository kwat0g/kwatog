import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const findings = readFileSync(resolve(root, 'docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md'), 'utf8');
const registry = JSON.parse(readFileSync(resolve(root, 'docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json'), 'utf8'));
const documented = [...findings.matchAll(/^### (F-\d{3})\b/gm)].map((match) => match[1]);
const allowedStatuses = new Set(['open', 'mitigated', 'verified', 'decision_required']);
const errors = [];
const ids = new Set();

for (const row of registry) {
  if (!/^F-\d{3}$/.test(row.id ?? '')) errors.push(`invalid id: ${row.id}`);
  if (ids.has(row.id)) errors.push(`duplicate id: ${row.id}`);
  ids.add(row.id);
  if (!allowedStatuses.has(row.status)) errors.push(`${row.id}: invalid status ${row.status}`);
  for (const field of ['owner', 'evidence_date', 'verification_scope']) {
    if (typeof row[field] !== 'string' || row[field].trim() === '') errors.push(`${row.id}: missing ${field}`);
  }
  if (!Object.hasOwn(row, 'regression_proof')) errors.push(`${row.id}: missing regression_proof field`);
  if (['verified', 'mitigated'].includes(row.status)
      && (typeof row.regression_proof !== 'string' || row.regression_proof.trim() === '')) {
    errors.push(`${row.id}: ${row.status} needs regression_proof`);
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(row.evidence_date ?? '')) errors.push(`${row.id}: invalid evidence_date`);
  if (row.status === 'decision_required' && (typeof row.policy_decision !== 'string' || row.policy_decision.trim() === '')) {
    errors.push(`${row.id}: decision_required needs policy_decision`);
  }
}

for (const id of documented) if (!ids.has(id)) errors.push(`${id}: missing lifecycle row`);
for (const id of ids) if (!documented.includes(id)) errors.push(`${id}: lifecycle row has no finding`);

if (errors.length > 0) {
  console.error(errors.join('\n'));
  process.exit(1);
}

const counts = Object.fromEntries([...allowedStatuses].map((status) => [status, registry.filter((row) => row.status === status).length]));
console.log(`Audit lifecycle clean: ${registry.length} findings (${Object.entries(counts).map(([key, value]) => `${key}=${value}`).join(', ')}).`);
