import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuPencil, LuPlus, LuPrinter } from '@/lib/icons';
import { vendorsApi } from '@/api/accounting/vendors';
import { billsApi } from '@/api/accounting/bills';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { Td, Th, tableCls, theadTrCls, totalsTrCls, trCls } from '@/components/ui/table-cells';
import type { Bill } from '@/types/accounting';

export default function VendorDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const [bir2307Year, setBir2307Year] = useState(new Date().getFullYear());
  const [bir2307Quarter, setBir2307Quarter] = useState(Math.floor(new Date().getMonth() / 3) + 1);

  const {
    data: vendor,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['accounting', 'vendors', id],
    queryFn: () => vendorsApi.show(id),
    enabled: !!id,
  });
  const { data: billsData } = useQuery({
    queryKey: ['accounting', 'bills', { vendor_id: id }],
    queryFn: () => billsApi.list({ vendor_id: id, per_page: 50 }),
    enabled: !!id,
  });
  const {
    data: bir2307Data,
    isLoading: bir2307Loading,
    isError: bir2307Error,
    refetch: bir2307Refetch,
  } = useQuery({
    queryKey: ['accounting', 'vendors', id, 'bir-2307', bir2307Year, bir2307Quarter],
    queryFn: () => vendorsApi.bir2307(id, bir2307Year, bir2307Quarter),
    enabled: !!id && can('accounting.bills.view') && bir2307Year >= 2000 && bir2307Year <= 2100,
  });

  if (isLoading || (!vendor && !isError)) return <SkeletonDetail />;
  if (isError)
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load vendor"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  if (!vendor) return null;

  const billColumns: Column<Bill>[] = [
    {
      key: 'bill_number',
      header: 'Bill no',
      cell: (r) => <span className="font-mono">{r.bill_number}</span>,
    },
    { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'due_date', header: 'Due', cell: (r) => <NumCell>{formatDate(r.due_date)}</NumCell> },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell>,
    },
    {
      key: 'balance',
      header: 'Balance',
      align: 'right',
      cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={chipVariantForStatus(r.status)}>{r.status_label ?? r.status}</Chip>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span>{vendor.name}</span>
            <Chip variant={vendor.is_active ? 'success' : 'neutral'}>
              {vendor.is_active ? 'active' : 'inactive'}
            </Chip>
          </div>
        }
        backTo="/accounting/vendors"
        backLabel="Vendors"
        actions={
          <div className="flex gap-1.5">
            {can('accounting.bills.create') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuPlus size={14} />}
                onClick={() => navigate(`/accounting/bills/create?vendor_id=${vendor.id}`)}
              >
                New bill
              </Button>
            )}
            {can('accounting.vendors.manage') && (
              <Button
                variant="primary"
                size="sm"
                icon={<LuPencil size={14} />}
                onClick={() => navigate(`/accounting/vendors/${vendor.id}/edit`)}
              >
                Edit
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <StatCard label="Open Balance" value={formatPeso(vendor.open_balance)} />
        <StatCard
          label="Payment Terms"
          value={vendor.payment_terms_days == null ? '—' : `${vendor.payment_terms_days} days`}
        />
        <StatCard label="Bills" value={vendor.bills_count ?? billsData?.meta.total ?? '—'} />
      </div>

      <div className="px-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <Panel title="Contact" className="col-span-1">
          <dl className="text-xs space-y-2">
            <div>
              <dt className="text-muted">Contact person</dt>
              <dd>{vendor.contact_person ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-muted">Email</dt>
              <dd>{vendor.email ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-muted">Phone</dt>
              <dd className="font-mono">{vendor.phone ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-muted">TIN</dt>
              <dd className="font-mono">{vendor.tin ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-muted">Address</dt>
              <dd>{vendor.address ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-muted">EWT classification</dt>
              <dd>{vendor.withholding_tax_label ?? vendor.withholding_tax_type ?? '—'}</dd>
            </div>
          </dl>
        </Panel>
        <Panel title="Bills" className="col-span-2">
          {billsData && billsData.data.length > 0 ? (
            <DataTable
              onRowClick={(r) => navigate(`/accounting/bills/${r.id}`)}
              columns={billColumns}
              data={billsData.data}
              meta={billsData.meta}
            />
          ) : (
            <EmptyState icon="inbox" title="No bills yet" />
          )}
        </Panel>
      </div>

      {can('accounting.bills.view') && (
        <div className="px-5 pt-4">
          <Panel title="BIR 2307 — Certificate of Creditable Tax Withheld">
            <div className="grid grid-cols-3 gap-3 mb-4">
              <Input
                label="Year"
                type="number"
                value={bir2307Year}
                onChange={(e) => setBir2307Year(Number(e.target.value))}
              />
              <Select
                label="Quarter"
                value={bir2307Quarter}
                onChange={(e) => setBir2307Quarter(Number(e.target.value))}
              >
                <option value={1}>Q1</option>
                <option value={2}>Q2</option>
                <option value={3}>Q3</option>
                <option value={4}>Q4</option>
              </Select>
              <div className="flex items-end">
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => void bir2307Refetch()}
                  loading={bir2307Loading}
                  disabled={bir2307Loading}
                >
                  Fetch
                </Button>
              </div>
            </div>

            {bir2307Loading && <p className="text-sm text-muted">Loading…</p>}
            {bir2307Error && (
              <EmptyState
                icon="alert-circle"
                title="Failed to load BIR 2307 data"
                action={
                  <Button variant="secondary" size="sm" onClick={() => void bir2307Refetch()}>
                    Retry
                  </Button>
                }
              />
            )}
            {bir2307Data && bir2307Data.income_payments.length > 0 && (
              <>
                <div className="grid grid-cols-2 gap-4 mb-4 text-sm">
                  <div>
                    <dt className="text-xs uppercase tracking-wider text-muted mb-0.5">Vendor</dt>
                    <dd>{bir2307Data.vendor.name}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wider text-muted mb-0.5">TIN</dt>
                    <dd className="font-mono">{bir2307Data.vendor.tin ?? '—'}</dd>
                  </div>
                  <div className="col-span-2">
                    <dt className="text-xs uppercase tracking-wider text-muted mb-0.5">Address</dt>
                    <dd>{bir2307Data.vendor.address ?? '—'}</dd>
                  </div>
                </div>

                <div className="overflow-x-auto mb-4">
                  <table className={`${tableCls} min-w-full`}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Month</Th>
                        <Th>ATC</Th>
                        <Th align="right">Income payment</Th>
                        <Th align="right">Tax withheld</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {bir2307Data.income_payments.map((payment, idx) => (
                        <tr key={idx} className={trCls}>
                          <Td>
                            {new Date(bir2307Data.year, payment.month - 1).toLocaleString('en-US', {
                              month: 'long',
                            })}
                          </Td>
                          <Td className="text-muted text-xs">{payment.atc ?? '—'}</Td>
                          <Td align="right" mono>
                            {formatPeso(payment.gross_amount)}
                          </Td>
                          <Td align="right" mono className="font-medium">
                            {formatPeso(payment.tax_withheld)}
                          </Td>
                        </tr>
                      ))}
                      <tr className={totalsTrCls}>
                        <Td colSpan={2}>Total</Td>
                        <Td align="right" mono>
                          {formatPeso(bir2307Data.summary.total_gross)}
                        </Td>
                        <Td align="right" mono>
                          {formatPeso(bir2307Data.summary.total_tax_withheld)}
                        </Td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuPrinter size={14} />}
                  onClick={() =>
                    void downloadAuthenticatedFile(
                      vendorsApi.bir2307PdfUrl(id, bir2307Year, bir2307Quarter),
                      { openInNewTab: true, errorMessage: 'Failed to generate BIR 2307 PDF.' },
                    )
                  }
                >
                  Print
                </Button>
              </>
            )}
            {bir2307Data && bir2307Data.income_payments.length === 0 && (
              <EmptyState
                icon="inbox"
                title={`No tax withheld from this vendor in Q${bir2307Quarter} ${bir2307Year}.`}
              />
            )}
          </Panel>
        </div>
      )}
    </div>
  );
}
