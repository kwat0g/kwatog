import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import { TableScrollRegion } from './TableScrollRegion';

it('labels the scroll region and updates its horizontal edge cues', () => {
  const { container } = render(
    <TableScrollRegion label="GRN line items">
      <table><tbody><tr><td>Item</td></tr></tbody></table>
    </TableScrollRegion>,
  );
  const scroller = screen.getByRole('region', { name: 'GRN line items' });
  const frame = container.querySelector('.scroll-edge-frame');
  Object.defineProperties(scroller, {
    scrollWidth: { configurable: true, value: 600 },
    offsetWidth: { configurable: true, value: 300 },
    clientWidth: { configurable: true, value: 300 },
    scrollLeft: { configurable: true, writable: true, value: 0 },
  });

  fireEvent.scroll(scroller);
  expect(frame).toHaveAttribute('data-more-right', 'true');
  scroller.scrollLeft = 300;
  fireEvent.scroll(scroller);
  expect(frame).toHaveAttribute('data-more-left', 'true');
  expect(frame).toHaveAttribute('data-more-right', 'false');
});
