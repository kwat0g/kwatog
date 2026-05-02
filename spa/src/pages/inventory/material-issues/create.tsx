import { Link } from 'react-router-dom';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/layout/PageHeader';

export default function CreateMaterialIssuePage() {
  return (
    <div>
      <PageHeader title="New material issue" backTo="/inventory/material-issues" backLabel="Material issues" />
      <EmptyState icon="alert-circle" title="Sprint 6 — Work orders required"
        description="Material issuance against a work order will be wired in Sprint 6 once WO records exist."
        action={<Link to="/inventory/material-issues"><Button variant="secondary">Back to list</Button></Link>} />
    </div>
  );
}
