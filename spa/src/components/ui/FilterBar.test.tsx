import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { FilterBar } from './FilterBar';

describe('FilterBar', () => {
  it('can enforce an endpoint search limit', () => {
    render(<FilterBar searchMaxLength={120} searchPlaceholder="Search decisions" />);

    expect(screen.getByRole('textbox', { name: 'Search decisions' })).toHaveAttribute('maxLength', '120');
  });

  it('shows how many filters are currently narrowing the list', () => {
    render(
      <FilterBar
        filters={[{
          key: 'status',
          label: 'Status',
          type: 'select',
          options: [{ value: 'active', label: 'Active' }],
        }]}
        values={{ status: 'active', search: 'bolt' }}
      />,
    );

    expect(screen.getByText('2 active filters')).toBeInTheDocument();
  });

  it('uses a searchable, keyboard-operable picker for filters', () => {
    const onFilter = vi.fn();
    render(
      <FilterBar
        filters={[{
          key: 'department',
          label: 'Department',
          type: 'select',
          options: [
            { value: 'finance', label: 'Finance' },
            { value: 'operations', label: 'Operations' },
          ],
        }]}
        values={{}}
        onFilter={onFilter}
      />,
    );

    const picker = screen.getByRole('combobox', { name: 'Department' });
    fireEvent.focus(picker);
    fireEvent.change(picker, { target: { value: 'Oper' } });
    fireEvent.keyDown(picker, { key: 'ArrowDown' });
    fireEvent.keyDown(picker, { key: 'Enter' });

    expect(onFilter).toHaveBeenCalledWith('department', 'operations');
  });

  it('prefixes blank “all” options with their field name', () => {
    render(
      <FilterBar
        filters={[{
          key: 'pay_type',
          label: 'Pay type',
          type: 'select',
          options: [
            { value: '', label: 'All types' },
            { value: 'salary', label: 'Salary' },
          ],
        }]}
        values={{}}
      />,
    );

    expect(screen.getByRole('combobox', { name: 'Pay type' })).toHaveValue('Pay type: All types');

  });

  it('keeps trailing actions in the shared toolbar end slot', () => {
    const { container } = render(<FilterBar actions={<button type="button">Export</button>} />);

    expect(screen.getByRole('button', { name: 'Export' }).parentElement).toHaveAttribute('data-toolbar-end');
    expect(container.querySelector('[data-layout="filter-bar"]')).toHaveClass('px-5');
  });
});
