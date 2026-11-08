import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { Button } from './Button';
import { DataTable, type Column } from './DataTable';

describe('DataTable row emphasis and actions', () => {
  it('emphasizes the primary column and keeps row actions keyboard reachable', () => {
    const row = { id: 'employee-1', name: 'Ada Lovelace' };
    const columns: Column<typeof row>[] = [
      { key: 'name', header: 'Name', primary: true, cell: (item) => item.name },
    ];
    const onPreview = vi.fn();
    render(
      <DataTable
        columns={columns}
        data={[row]}
        rowActions={(item) => (
          <Button size="sm" aria-label={`Preview ${item.name}`} onClick={onPreview}>Preview</Button>
        )}
      />,
    );

    const previewButtons = screen.getAllByRole('button', { name: 'Preview Ada Lovelace' });
    expect(previewButtons).toHaveLength(2); // Compact cards and the desktop table are both rendered for responsive layout.
    expect(previewButtons.every((button) => button.tabIndex === 0)).toBe(true);
    expect(screen.getByRole('cell', { name: 'Ada Lovelace' })).toHaveClass('font-medium', 'text-primary');
  });

  it('keeps the table shape and selected density while loading', () => {
    const columns: Column<{ id: string; name: string }>[] = [
      { key: 'name', header: 'Name', cell: (item) => item.name, primary: true },
    ];
    const { container } = render(
      <DataTable
        columns={columns}
        data={[{ id: '1', name: 'Stale row' }]}
        density="compact"
        loading
      />,
    );

    const table = screen.getByRole('table');
    const rows = container.querySelectorAll('tbody tr');
    expect(table).toHaveAttribute('aria-busy', 'true');
    expect(screen.queryByText('Stale row')).not.toBeInTheDocument();
    expect(rows).toHaveLength(5);
    expect(rows[0]).toHaveClass('h-row-compact');
    expect(rows[0]).toHaveAttribute('aria-hidden', 'true');
  });
});
