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

  it('resizes a column with the keyboard, persists it, and resets the width', () => {
    const { container } = render(
      <DataTable columns={columns} data={data} tableKey="inventory.items" />,
    );
    const separator = screen.getByRole('separator', { name: 'Name column width' });

    fireEvent.keyDown(separator, { key: 'ArrowRight' });
    expect(separator).toHaveAttribute('aria-valuenow', '176');
    expect(container.querySelector('thead th[scope="col"]')).toHaveStyle({ width: '176px' });
    expect(authApi.updatePreferences).toHaveBeenLastCalledWith({
      table_preferences: { 'inventory.items': { columnWidths: { name: 176 } } },
    });

    fireEvent.click(screen.getByRole('button', { name: 'Reset widths' }));
    expect(screen.queryByRole('button', { name: 'Reset widths' })).not.toBeInTheDocument();
    expect(authApi.updatePreferences).toHaveBeenLastCalledWith({
      table_preferences: { 'inventory.items': { columnWidths: {} } },
    });
  });

  it('lets a saved resize override a larger page-authored minimum width', () => {
    const constrainedColumns: Column<Row>[] = [
      { ...columns[0], className: 'min-w-[180px]' },
    ];
    const { container } = render(
      <DataTable columns={constrainedColumns} data={data} tableKey="inventory.items" />,
    );
    const separator = screen.getByRole('separator', { name: 'Name column width' });

    for (let step = 0; step < 6; step += 1) fireEvent.keyDown(separator, { key: 'ArrowLeft' });

    expect(separator).toHaveAttribute('aria-valuenow', '64');
    expect(container.querySelector('thead th[scope="col"]')).toHaveStyle({ width: '64px', minWidth: '64px' });
    expect(container.querySelector('tbody td')).toHaveStyle({ width: '64px', minWidth: '64px' });
  });

  it('clamps saved widths when a column minimum changes', () => {
    useTablePrefsStore.getState().hydrate('user-1', {
      'inventory.items': { columnWidths: { name: 64 } },
    });
    const constrainedColumns: Column<Row>[] = [
      { ...columns[0], minWidth: 180 },
    ];
    const { container } = render(
      <DataTable columns={constrainedColumns} data={data} tableKey="inventory.items" />,
    );
    const separator = screen.getByRole('separator', { name: 'Name column width' });

    expect(separator).toHaveAttribute('aria-valuenow', '180');
    expect(container.querySelector('thead th[scope="col"]')).toHaveStyle({ width: '180px', minWidth: '180px' });
  });
});
