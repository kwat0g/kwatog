import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DonutBreakdown } from './DonutBreakdown';

describe('DonutBreakdown', () => {
  it('renders repeated labels without duplicate React keys', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});

    try {
      render(<DonutBreakdown label="Zones" data={[
        { name: 'Zone l8', value: 100, color: 'red' },
        { name: 'Zone l8', value: 100, color: 'blue' },
      ]} />);

      expect(error.mock.calls.flat().join(' ')).not.toContain('Encountered two children with the same key');
    } finally {
      error.mockRestore();
    }
  });
});
