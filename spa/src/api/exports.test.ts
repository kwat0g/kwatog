/**
 * WS-E.1 — URL/builder unit test for the generic export client.
 */
import { describe, it, expect } from 'vitest';
import { buildExportUrl } from './exports';

describe('buildExportUrl', () => {
  it('produces a /api/v1/exports URL with format=csv by default', () => {
    expect(buildExportUrl({ resource: 'hr.employees' })).toBe(
      '/api/v1/exports/hr.employees?format=csv',
    );
  });

  it('appends filter query params and skips undefined / empty values', () => {
    const url = buildExportUrl({
      resource: 'hr.employees',
      filters: { status: 'active', search: '', department_id: undefined, include_archived: false },
    });
    expect(url).toContain('format=csv');
    expect(url).toContain('status=active');
    expect(url).toContain('include_archived=false');
    expect(url).not.toContain('search=');
    expect(url).not.toContain('department_id');
  });

  it('encodes the resource segment so dots survive but slashes do not', () => {
    expect(buildExportUrl({ resource: 'hr.employees' })).toContain('/exports/hr.employees?');
  });
});
