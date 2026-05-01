import { Outlet } from 'react-router-dom';

/**
 * Mobile-friendly shell for the self-service portal.
 * Full implementation lands in Task 74.
 */
export function SelfServiceLayout() {
  return (
    <div className="min-h-screen bg-canvas">
      <Outlet />
    </div>
  );
}

export default SelfServiceLayout;
