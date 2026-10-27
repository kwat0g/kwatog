import { expect, it } from 'vitest';
import { horizontalScrollEdges } from './horizontalScrollEdges';

it('excludes the reserved scrollbar gutter from horizontal edge state', () => {
  const element = document.createElement('div');
  element.style.borderLeft = '1px solid';
  element.style.borderRight = '2px solid';
  Object.defineProperties(element, {
    scrollWidth: { configurable: true, value: 420 },
    offsetWidth: { value: 303 },
    clientWidth: { value: 285 },
    scrollLeft: { configurable: true, writable: true, value: 0 },
  });

  expect(horizontalScrollEdges(element)).toEqual({ left: false, right: true });
  element.scrollLeft = 60;
  expect(horizontalScrollEdges(element)).toEqual({ left: true, right: true });
  element.scrollLeft = 120;
  expect(horizontalScrollEdges(element)).toEqual({ left: true, right: false });

  Object.defineProperty(element, 'scrollWidth', { value: 300 });
  element.scrollLeft = 0;
  expect(horizontalScrollEdges(element)).toEqual({ left: false, right: false });
});
