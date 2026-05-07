/**
 * Series E (E3) — document vault list panel. Drop into any detail page's
 * "Documents" tab. Wraps DataTable with the 5 mandatory states.
 */

import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Eye, Download } from 'lucide-react';
import { client } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatDateTime } from '@/lib/formatDate';
import type { DocumentRecord } from '@/types/documents';
import type { PaginatedResponse } from '@/types';
import { PdfPreviewModal } from './PdfPreviewModal';

interface DocumentListProps {
  /** Module-relative path that returns vault rows for an entity. Must
   * accept ?entity_type=...&entity_id=... in the query string. */
  endpoint?: string;
  /** Filters passed straight through to the listing endpoint. */
  filters?: Record<string, string | number | undefined>;
}

function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / 1024 / 1024).toFixed(2)} MB`;
}

export function DocumentList({ endpoint = '/documents', filters }: DocumentListProps) {
  const [previewing, setPreviewing] = useState<DocumentRecord | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['documents', endpoint, filters],
    queryFn: () =>
      client
        .get<PaginatedResponse<DocumentRecord>>(endpoint, { params: filters })
        .then((r) => r.data),
    placeholderData: (prev) => prev,
  });

  const columns = useMemo<Column<DocumentRecord>[]>(
    () => [
      {
        key: 'document_label',
        header: 'Type',
        cell: (row) => <Chip variant={row.is_confidential ? 'danger' : 'info'}>{row.document_label}</Chip>,
      },
      {
        key: 'file',
        header: 'File',
        cell: (row) => (
          <StackedCell
            primary={row.file_name}
            secondary={<span className="font-mono tabular-nums text-muted">{formatBytes(row.file_size)}</span>}
          />
        ),
      },
      {
        key: 'generated_at',
        header: 'Generated',
        cell: (row) => <NumCell>{formatDateTime(row.generated_at)}</NumCell>,
      },
      {
        key: 'generated_by',
        header: 'By',
        cell: (row) => (row.generated_by ? row.generated_by.name : <span className="text-muted">System</span>),
      },
      {
        key: 'actions',
        header: '',
        cell: (row) => (
          <div className="flex items-center gap-1.5 justify-end">
            <Button
              size="sm"
              variant="ghost"
              icon={<Eye size={14} />}
              onClick={() => setPreviewing(row)}
            >
              View
            </Button>
            <a href={row.download_url} download rel="noopener">
              <Button size="sm" variant="ghost" icon={<Download size={14} />}>
                Download
              </Button>
            </a>
          </div>
        ),
        align: 'right',
      },
    ],
    [],
  );

  if (isLoading && !data) {
    return <SkeletonTable columns={5} rows={4} />;
  }

  if (isError) {
    return (
      <EmptyState
        title="Failed to load documents"
        description="An error occurred while loading the document list. Please try again."
        action={
          <Button variant="secondary" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  }

  if (data && data.data.length === 0) {
    return (
      <EmptyState
        title="No documents generated yet"
        description="Documents created from this record will appear here. Generate a PDF to get started."
      />
    );
  }

  return (
    <>
      {data && (
        <DataTable<DocumentRecord>
          columns={columns}
          data={data.data}
          getRowId={(row) => row.id}
        />
      )}
      <PdfPreviewModal
        isOpen={!!previewing}
        onClose={() => setPreviewing(null)}
        document={previewing}
      />
    </>
  );
}
