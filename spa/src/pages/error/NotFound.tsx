import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';

export default function NotFoundPage() {
  return (
    <div className="min-h-screen flex items-center justify-center bg-canvas">
      <EmptyState
        icon="file-question"
        title="Page not found"
        description="The page you're looking for doesn't exist or has been moved."
        action={
          <Link to="/dashboard">
            <Button variant="primary">Return home</Button>
          </Link>
        }
      />
    </div>
  );
}
