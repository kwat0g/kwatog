import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DataTable, type Column } from './DataTable';
import { useTablePrefsStore } from '@/stores/tablePrefsStore';

vi.mock('@/api/auth', () => ({
  authApi: { updatePreferences: vi.fn().mockResolvedValue({}) },
}));

import { authApi } from '@/api/auth';

interface Row { id: string; name: string }
const columns: Column<Row>[] = [{ key: 'name', header: 'Name', cell: (row) => row.name }];
const data: Row[] = [{ id: '1', name: 'Alpha' }, { id: '2', name: 'Beta' }];

beforeEach(() => {
  useTablePrefsStore.getState().clear();
  useTablePrefsStore.getState().hydrate('user-1');
  window.history.replaceState({}, '', '/layout-test/table');
  document.documentElement.setAttribute('data-theme', 'light');
  vi.clearAllMocks();
});

afterEach(cleanup);

describe('DataTable preferences', () => {
  it('applies and persists density even when the page has no explicit table key', () => {
    const { container } = render(<DataTable columns={columns} data={data} />);
    expect(container.querySelector('[data-layout="data-table"]')).toHaveAttribute('data-density', 'compact');
    fireEvent.change(screen.getByRole('combobox', { name: 'Density' }), { target: { value: 'compact' } });

    expect(container.querySelector('[data-layout="data-table"]')).toHaveAttribute('data-density', 'compact');
    expect(container.querySelector('tbody tr')).toHaveClass('h-row-compact');
    const payload = vi.mocked(authApi.updatePreferences).mock.calls[0]?.[0].table_preferences;
    expect(Object.values(payload ?? {})[0]).toEqual({ density: 'compact' });
  });

  it('restores a saved density and renders zebra rows using the active theme token', () => {
    useTablePrefsStore.getState().setDensity('stock-adjustments', 'compact');
    const { container, rerender } = render(
      <DataTable columns={columns} data={data} tableKey="stock-adjustments" />,
    );
    expect(container.querySelector('[data-layout="data-table"]')).toHaveAttribute('data-density', 'compact');
    expect(container.querySelectorAll('tbody tr')[1]).toHaveClass('bg-[var(--bg-zebra-even)]');

    document.documentElement.setAttribute('data-theme', 'dark');
    rerender(<DataTable columns={columns} data={data} tableKey="stock-adjustments" />);
    expect(container.querySelectorAll('tbody tr')[1]).toHaveClass('bg-[var(--bg-zebra-even)]');
  });
});
