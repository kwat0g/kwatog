import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { StatCard } from './StatCard';

afterEach(cleanup);

it.each([
  ['up', 'Increase'],
  ['down', 'Decrease'],
  ['neutral', 'No change'],
] as const)('announces a %s delta direction', (direction, description) => {
  render(
    <StatCard
      label="Delivery"
      value="96.4%"
      delta={{ value: '1.8 pts', direction }}
    />,
  );

  expect(screen.getByText(description, { selector: '.sr-only' })).toBeInTheDocument();
  expect(screen.getByText('1.8 pts')).toBeVisible();
});
