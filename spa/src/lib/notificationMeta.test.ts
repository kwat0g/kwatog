import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
 KNOWN_NOTIFICATION_TYPES,
 bucketLabel,
 dateBucket,
 notificationMeta,
 timeAgo,
} from './notificationMeta';

/**
 * The bell, the list page and the preferences page all key off the same
 * backend type strings. When `notificationMeta` matched Laravel class names
 * while the backend sent dot keys, every unmatched type rendered as a generic
 * grey bell AND landed in the `system` group — which is what the filter chips
 * read, so "Approvals" hid most approvals. These tests pin the mapping to the
 * PHP catalog so the two cannot drift apart again silently.
 */

const CATALOG_PATH = join(
 dirname(fileURLToPath(import.meta.url)),
 '../../../api/app/Common/Services/NotificationCatalog.php',
);

/** Every `['key' => 'x', ...]` entry in NotificationCatalog::defaults(). */
function catalogKeys(): string[] {
 const php = readFileSync(CATALOG_PATH, 'utf8');
 const keys = [...php.matchAll(/'key'\s*=>\s*'([^']+)'/g)].map((m) => m[1]);
 return [...new Set(keys)];
}

describe('notificationMeta', () => {
 it('resolves dot-namespaced backend keys, not just class names', () => {
 // The exact regression: these produced the default bell + `system` group.
 expect(notificationMeta('chain.pr_approved').group).toBe('approvals');
 expect(notificationMeta('chain.po_approved').group).toBe('approvals');
 expect(notificationMeta('chain.payslip_ready').label).toBe('Payroll');
 expect(notificationMeta('inventory.low_stock').group).toBe('alerts');
 expect(notificationMeta('maintenance.breakdown').label).toBe('Maintenance');
 });

 it('has an entry for every type in the backend catalog', () => {
 const known = new Set(KNOWN_NOTIFICATION_TYPES);
 const missing = catalogKeys().filter((key) => !known.has(key));

 expect(
 missing,
 `These backend types render as a generic bell. Add them to BY_TYPE in notificationMeta.ts:\n ${missing.join('\n ')}`,
 ).toEqual([]);
 });

 it('does not map types the backend catalog no longer contains', () => {
 const catalog = new Set(catalogKeys());
 const stale = KNOWN_NOTIFICATION_TYPES.filter((key) => !catalog.has(key));

 expect(stale, `Stale mappings with no backend counterpart:\n ${stale.join('\n ')}`).toEqual([]);
 });

 it('assigns every known type a real icon and a valid group', () => {
 for (const type of KNOWN_NOTIFICATION_TYPES) {
 const meta = notificationMeta(type);
 expect(meta.icon, `${type} icon`).toBeTruthy();
 expect(meta.label, `${type} label`).toBeTruthy();
 expect(['approvals', 'alerts', 'system'], `${type} group`).toContain(meta.group);
 }
 });

 it('keeps the legacy class-name fallback working', () => {
 // Rows written before the dot-key migration are still in the table.
 const meta = notificationMeta('App\\Modules\\Quality\\Notifications\\NcrCreated');
 expect(meta.group).toBe('alerts');
 expect(meta.label).toBe('Quality');
 });

 it('falls back to a generic bell for unknown types', () => {
 const meta = notificationMeta('something.entirely.new');
 expect(meta.group).toBe('system');
 expect(meta.label).toBe('System');
 });

 it('handles a missing type without throwing', () => {
 expect(notificationMeta(undefined).label).toBe('System');
 });
});

describe('dateBucket', () => {
 it('buckets by recency', () => {
 const now = new Date();
 const hoursAgo = (h: number) => new Date(now.getTime() - h * 3600_000).toISOString();

 expect(dateBucket(now.toISOString())).toBe('today');
 expect(dateBucket(hoursAgo(24 * 5))).toBe('this_week');
 expect(dateBucket(hoursAgo(24 * 30))).toBe('older');
 });

 it('labels every bucket', () => {
 for (const bucket of ['today', 'yesterday', 'this_week', 'older'] as const) {
 expect(bucketLabel(bucket)).toBeTruthy();
 }
 });
});

describe('timeAgo', () => {
 it('formats recent timestamps compactly', () => {
 const now = Date.now();
 expect(timeAgo(new Date(now - 30_000).toISOString())).toBe('just now');
 expect(timeAgo(new Date(now - 5 * 60_000).toISOString())).toBe('5m ago');
 expect(timeAgo(new Date(now - 3 * 3600_000).toISOString())).toBe('3h ago');
 expect(timeAgo(new Date(now - 2 * 86_400_000).toISOString())).toBe('2d ago');
 });

 it('returns an empty string for an unparseable date rather than NaN', () => {
 expect(timeAgo('not-a-date')).toBe('');
 });
});
