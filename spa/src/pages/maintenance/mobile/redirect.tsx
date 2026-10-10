import { Navigate, useParams } from 'react-router-dom';

export default function OldWorkOrderDetailRedirect() {
 const { mwoId } = useParams<{ mwoId: string }>();
 return <Navigate to={`/maintenance/work-orders/${mwoId}`} replace />;
}
