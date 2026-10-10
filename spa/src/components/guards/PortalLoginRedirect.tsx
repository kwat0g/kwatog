import { Navigate, useLocation } from 'react-router-dom';

export function PortalLoginRedirect({ realm }: { realm: 'supplier' | 'customer' }) {
  const { search } = useLocation();
  const from = new URLSearchParams(search).get('from');
  const portalPath = `/portal/${realm}`;
  const returnPath = from === portalPath || from?.startsWith(`${portalPath}/`) ? from : undefined;

  return <Navigate to="/sign-in" state={{ realm, from: returnPath }} replace />;
}
