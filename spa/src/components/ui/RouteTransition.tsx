// The design system reserves motion for state changes and direct manipulation.
// Route changes should remain immediate so navigation does not look like a
// loading state or delay focus movement.

import type { ReactNode } from 'react';

interface Props {
  children: ReactNode;
}

export function RouteTransition({ children }: Props) {
  return <div>{children}</div>;
}
