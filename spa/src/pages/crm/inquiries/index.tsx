import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { inquiriesApi, type InquiryListParams } from '@/api/crm/inquiries';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';
import type { ContactInquiry, ContactInquiryStatus } from '@/types/crm';

const variant: Record<ContactInquiryStatus, 'info' | 'warning' | 'success' | 'neutral'> = {
 new: 'info',
 in_progress: 'warning',
 converted: 'success',
 closed: 'neutral',
};

export default function InquiryListPage() {
 const navigate = useNavigate();
 const [filters, setFilters] = useState<InquiryListParams>({ page: 1, per_page: 25 });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'inquiries', filters],
 queryFn: () => inquiriesApi.list(filters),
 placeholderData: (prev) => prev });

 const columns: Column<ContactInquiry>[] = [
 { key: 'no', header: 'Inquiry #', cell: (r) => <span className="font-mono">{r.inquiry_no}</span> },
 { key: 'sender', header: 'From', cell: (r) => (
 <div>
 <div className="font-medium">{r.full_name}</div>
 <div className="text-xs text-muted">{r.company ?? r.email}</div>
 </div>
 ) },
 { key: 'message', header: 'Message', cell: (r) => (
 <span className="line-clamp-1 text-muted">{r.message}</span>
 ) },
 { key: 'received', header: 'Received', cell: (r) => (
 <span className="font-mono tabular-nums text-xs">{formatDate(r.created_at)}</span>
 ) },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{r.status_label}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 { value: 'new', label: 'New' },
 { value: 'in_progress', label: 'In progress' },
 { value: 'converted', label: 'Converted' },
 { value: 'closed', label: 'Closed' },
 ]},
 ];

 return (
 <div>
 <PageHeader title="Inquiries"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'inquiry' : 'inquiries'}` : undefined} />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search name, company, email, or inquiry #…"
 />
 {isLoading && !data && <SkeletonTable columns={5} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load inquiries"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <EmptyState icon="inbox" title="No inquiries"
 description="Messages sent through the public contact form arrive here." />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable onRowClick={(r) => navigate(`/crm/inquiries/${r.id}`)}
 columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
 </div>
 )}
 </div>
 );
}
