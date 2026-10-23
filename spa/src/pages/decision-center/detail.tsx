/** DSS Wave 0-4 — Recommendation detail route. */
import { useQuery } from '@tanstack/react-query';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { dssApi } from '@/api/dss';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import RecommendationDetails from './RecommendationDetails';

export default function RecommendationDetailPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const backTo = `/decision-center${location.search}`;
  const {
    data: recommendation,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['dss', 'recommendations', id],
    queryFn: () => dssApi.show(id),
  });

  if (isLoading && !recommendation) return <SkeletonTable columns={1} rows={6} />;

  if (isError || !recommendation) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Could not load recommendation"
        description="The recommendation could not be loaded. Check your connection and try again."
        action={
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
            <Button variant="ghost" onClick={() => navigate(backTo)}>
              Back to Decision Center
            </Button>
          </div>
        }
      />
    );
  }

  return <RecommendationDetails recommendation={recommendation} backTo={backTo} />;
}
