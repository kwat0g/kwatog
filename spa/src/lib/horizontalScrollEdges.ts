export function horizontalScrollEdges(element: HTMLElement) {
  const style = getComputedStyle(element);
  const borders =
    (Number.parseFloat(style.borderLeftWidth) || 0) +
    (Number.parseFloat(style.borderRightWidth) || 0);
  // The stable scrollbar gutter is fixed; exclude it from the horizontal travel range.
  const maxScroll = element.scrollWidth - element.offsetWidth + borders;
  return {
    left: element.scrollLeft > 1,
    right: maxScroll - element.scrollLeft > 1,
  };
}
