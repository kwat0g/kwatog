import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StatusTabs } from './StatusTabs';

const items = [
  { value: 'active', label: 'Active', count: 12 },
  { value: 'leave', label: 'On leave', count: 0 },
];

describe('StatusTabs', () => {
  it('shows zero counts and links tabs to their results panel', () => {
    render(<StatusTabs items={items} value="active" onChange={() => undefined} label="Employee status" panelId="results" />);

    expect(screen.getByRole('tablist', { name: 'Employee status' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'On leave 0' })).toHaveAttribute('aria-controls', 'results');
    expect(screen.getByText('0')).toHaveClass('text-subtle');
  });

  it('changes selection and focus with arrow keys', () => {
    const onChange = vi.fn();
    render(<StatusTabs items={items} value="active" onChange={onChange} label="Employee status" />);
    const active = screen.getByRole('tab', { name: 'Active 12' });

    active.focus();
    fireEvent.keyDown(active, { key: 'ArrowRight' });

    expect(onChange).toHaveBeenCalledWith('leave');
    expect(screen.getByRole('tab', { name: 'On leave 0' })).toHaveFocus();
  });
});
