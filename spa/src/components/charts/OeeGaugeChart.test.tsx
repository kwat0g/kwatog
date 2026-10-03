import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { OeeGaugeChart } from './OeeGaugeChart';

describe('OeeGaugeChart', () => {
  it('renders a fixed-size gauge without a chart warning', () => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {});

    try {
      render(<OeeGaugeChart oee={0.85} availability={0.9} performance={0.95} quality={1} />);

      expect(screen.getByText('85.0%')).toBeInTheDocument();
      expect(screen.getByText('OEE')).toBeInTheDocument();
      expect(warning).not.toHaveBeenCalledWith(expect.stringContaining('both fixed numbers'));
    } finally {
      warning.mockRestore();
    }
  });
});
