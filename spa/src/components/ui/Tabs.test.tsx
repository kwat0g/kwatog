import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { Tabs } from './Tabs';

describe('Tabs', () => {
  it('moves keyboard focus and selection to the next enabled tab', () => {
    const onChange = vi.fn();
    render(
      <Tabs
        label="Employee sections"
        value="overview"
        onChange={onChange}
        items={[
          { key: 'overview', label: 'Overview' },
          { key: 'pay', label: 'Pay', disabled: true },
          { key: 'activity', label: 'Activity' },
        ]}
      />,
    );

    const overview = screen.getByRole('tab', { name: 'Overview' });
    const activity = screen.getByRole('tab', { name: 'Activity' });
    overview.focus();
    fireEvent.keyDown(overview, { key: 'ArrowRight' });

    expect(onChange).toHaveBeenCalledWith('activity');
    expect(activity).toHaveFocus();
  });
});
