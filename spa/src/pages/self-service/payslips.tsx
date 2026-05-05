/** Sprint 8 — Task 74 + Sprint P5. Self-service payslips, mobile-card layout. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { payrollsApi, type PayrollListParams } from '@/api/payroll/payrolls';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { Payroll } from '@/types/payroll';

/**
 * Self-service payslip list. Backend scopes results to the logged-in
 * employee — they only ever see their own payroll rows.
 *
 * Sprint P5: mobile-first card list (no DataTable). Each card has a 44px+
 * Download tap target so factory workers on phones can grab the PDF
 * without pinch-zoom.
 */
export default function SelfServicePayslipsPage() {
  const [filters, setFilters] = useState<PayrollListParams>({
    page: 1, per_page: 25, sort: 'created_at', direction: 'desc',
  });
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['my-payslips', filters],
    queryFn: () => payrollsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  return (
    <div className="px-4 py-4 space-y-3">
      <div className="flex items-baseline justify-between">
        <h1 className="text-base font-medium">My payslips</h1>
        {data && (
          <span className="text-xs text-muted font-mono tabular-nums">
            {data.meta.total} total
          </span>
        )}
      </div>

      {isLoading && !data && (
        <div className="space-y-2">
          {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-24 rounded-md" />)}
        </div>
      )}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load payslips"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="receipt"
          title="No payslips yet"
          description="Your payslip will appear here after the next payroll run."
        />
      )}

      {data && data.data.length > 0 && (
        <ul className="space-y-2">
          {data.data.map((p: Payroll) => (
            <li
              key={p.id}
              className="rounded-md border border-default bg-canvas p-3 flex items-start gap-3"
            >
              <div className="flex-1 min-w-0">
                <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                  Period
                </div>
                <div className="text-sm font-medium font-mono tabular-nums">
                  {p.computed_at ? formatDate(p.computed_at) : '—'}
                </div>

                <div className="grid grid-cols-3 gap-2 mt-2">
                  <Stat label="Gross" value={formatPeso(p.gross_pay)} />
                  <Stat label="Deductions" value={formatPeso(p.total_deductions)} />
                  <Stat label="Net" value={formatPeso(p.net_pay)} bold />
                </div>

                <div className="mt-2">
                  {p.error_message ? (
                    <Chip variant="danger">Error</Chip>
                  ) : (
                    <Chip variant="success">Ready</Chip>
                  )}
                </div>
              </div>

              {!p.error_message && (
                <a
                  href={payrollsApi.payslipUrl(p.id)}
                  target="_blank"
                  rel="noreferrer"
                  className="shrink-0 inline-flex items-center gap-1 px-3 h-11 text-sm rounded-md border border-default bg-canvas text-primary hover:bg-elevated"
                  aria-label={`Download payslip PDF for ${p.computed_at ? formatDate(p.computed_at) : 'this period'}`}
                >
                  <Download size={14} /> PDF
                </a>
              )}
            </li>
          ))}

          {data.meta.last_page > 1 && (
            <div className="flex items-center justify-between gap-2 pt-2">
              <Button
                variant="secondary"
                size="sm"
                disabled={(filters.page ?? 1) <= 1}
                onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) - 1 }))}
              >
                Previous
              </Button>
              <span className="text-xs text-muted font-mono tabular-nums">
                Page {data.meta.current_page} of {data.meta.last_page}
              </span>
              <Button
                variant="secondary"
                size="sm"
                disabled={(filters.page ?? 1) >= data.meta.last_page}
                onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}
              >
                Next
              </Button>
            </div>
          )}
        </ul>
      )}
    </div>
  );
}

function Stat({ label, value, bold }: { label: string; value: string; bold?: boolean }) {
  return (
    <div>
      <div className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</div>
      <div className={`font-mono tabular-nums text-sm ${bold ? 'font-medium text-primary' : ''}`}>
        {value}
      </div>
    </div>
  );
}
