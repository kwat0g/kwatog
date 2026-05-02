import { useParams, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Pencil } from 'lucide-react';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { usePermission } from '@/hooks/usePermission';

export default function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const canManage = can('crm.products.manage');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'products', 'detail', id],
    queryFn: () => productsApi.show(id!),
    enabled: !!id,
  });

  if (isLoading) {
    return (
      <div>
        <PageHeader title="Product" backTo="/crm/products" backLabel="Products" />
        <SkeletonDetail />
      </div>
    );
  }
  if (isError || !data) {
    return (
      <div>
        <PageHeader title="Product" backTo="/crm/products" backLabel="Products" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load product"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{data.part_number}</span>
            {data.is_active
              ? <Chip variant="success">Active</Chip>
              : <Chip variant="neutral">Inactive</Chip>}
            {data.has_bom && <Chip variant="info">BOM</Chip>}
          </div>
        }
        subtitle={data.name}
        backTo="/crm/products"
        backLabel="Products"
        actions={canManage && (
          <Button
            variant="secondary"
            size="sm"
            icon={<Pencil size={14} />}
            onClick={() => navigate(`/crm/products/${data.id}/edit`)}
          >
            Edit
          </Button>
        )}
      />

      <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-4">
          <Panel title="Overview">
            <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <dt className="text-muted">Part number</dt>
              <dd className="col-span-2 font-mono">{data.part_number}</dd>
              <dt className="text-muted">Name</dt>
              <dd className="col-span-2 font-medium">{data.name}</dd>
              <dt className="text-muted">UOM</dt>
              <dd className="col-span-2 font-mono">{data.unit_of_measure}</dd>
              <dt className="text-muted">Standard cost</dt>
              <dd className="col-span-2 font-mono tabular-nums">₱ {Number(data.standard_cost).toFixed(2)}</dd>
              <dt className="text-muted">Description</dt>
              <dd className="col-span-2">{data.description ?? <span className="text-muted">—</span>}</dd>
            </dl>
          </Panel>

          <Panel title="Bill of materials">
            {data.has_bom ? (
              <div className="text-sm text-muted">
                An active BOM exists for this product.{' '}
                <a href={`/mrp/boms/${data.id}`} className="text-accent hover:underline">View BOM</a>{' '}
                <span className="text-muted">(Sprint 6 Task 49)</span>
              </div>
            ) : (
              <div className="text-sm text-muted">No BOM yet — material planning is unavailable until one is created.</div>
            )}
          </Panel>
        </div>

        <div className="space-y-4">
          <Panel title="Quick links">
            <div className="space-y-2 text-sm">
              <a
                href={`/crm/sales-orders?product_id=${data.id}`}
                className="block text-accent hover:underline"
              >
                Recent sales orders →
              </a>
              <a
                href={`/crm/price-agreements?product_id=${data.id}`}
                className="block text-accent hover:underline"
              >
                Price agreements →
              </a>
              <a
                href={`/quality/inspection-specs/${data.id}`}
                className="block text-muted"
                aria-disabled
              >
                Inspection spec (Sprint 7)
              </a>
            </div>
          </Panel>
        </div>
      </div>
    </div>
  );
}
