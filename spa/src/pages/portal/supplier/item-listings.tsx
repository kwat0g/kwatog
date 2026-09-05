import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuPencil, LuPlus, LuX } from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { PortalSupplierListing, PortalSupplierListingInput, PortalListingStatus } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { PageHeader } from '@/components/layout/PageHeader';
import { useUrlFilters } from '@/hooks/useUrlFilters';

type ListingFilters = { page: number; per_page: number };

type ListingForm = {
  id: string | null;
  item_id: string;
  supplier_item_code: string;
  supplier_item_name: string;
  price: string;
  order_uom: string;
  base_qty_per_order_unit: string;
  lead_time_days: string;
  valid_until: string;
};

const emptyForm: ListingForm = {
  id: null,
  item_id: '',
  supplier_item_code: '',
  supplier_item_name: '',
  price: '',
  order_uom: '',
  base_qty_per_order_unit: '',
  lead_time_days: '',
  valid_until: '',
};

const statusLabel: Record<PortalListingStatus, string> = {
  pending: 'Pending review',
  approved: 'Approved',
  rejected: 'Rejected',
  superseded: 'Superseded',
};

export default function SupplierItemListingsPage() {
  const queryClient = useQueryClient();
  const [filters, setFilters] = useUrlFilters<ListingFilters>({ page: 1, per_page: 25 });
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<ListingForm>(emptyForm);

  const listings = useQuery({
    queryKey: ['portal', 'supplier', 'item-listings', filters],
    queryFn: () => supplierPortalApi.listItemListings(filters),
    placeholderData: (previous) => previous,
  });
  const catalog = useQuery({
    queryKey: ['portal', 'supplier', 'item-catalog'],
    queryFn: () => supplierPortalApi.itemCatalog(),
    enabled: showForm,
  });

  const selected = catalog.data?.find((item) => item.id === form.item_id);

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'item-listings'] });

  const submit = useMutation({
    mutationFn: (payload: PortalSupplierListingInput) =>
      form.id ? supplierPortalApi.updateItemListing(form.id, payload) : supplierPortalApi.createItemListing(payload),
    onSuccess: () => {
      toast.success(form.id ? 'Listing updated.' : 'Listing submitted for review.');
      setShowForm(false);
      setForm(emptyForm);
      invalidate();
    },
    onError: (error: Error & { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message ?? 'Could not save the listing.');
    },
  });

  const startCreate = () => {
    setForm(emptyForm);
    setShowForm(true);
  };

  const startEdit = (listing: PortalSupplierListing) => {
    setForm({
      id: listing.id,
      item_id: listing.item?.id ?? '',
      supplier_item_code: listing.supplier_item_code ?? '',
      supplier_item_name: listing.supplier_item_name ?? '',
      price: listing.price,
      order_uom: listing.order_uom ?? '',
      base_qty_per_order_unit: listing.base_qty_per_order_unit ?? '',
      lead_time_days: String(listing.lead_time_days),
      valid_until: listing.valid_until ?? '',
    });
    setShowForm(true);
    window.scrollTo({ top: 0 });
  };

  const submitForm = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!form.item_id) {
      toast.error('Choose the Ogami item this offer is for.');
      return;
    }
    const price = Number(form.price);
    if (!Number.isFinite(price) || price <= 0) {
      toast.error('Enter a price greater than zero.');
      return;
    }
    const lead = Number(form.lead_time_days);
    if (!Number.isInteger(lead) || lead < 0) {
      toast.error('Lead time must be a whole number of days.');
      return;
    }
    const conversion = form.base_qty_per_order_unit.trim();
    if (form.order_uom.trim() && (!conversion || Number(conversion) <= 0)) {
      toast.error('Enter how many base units are in one order unit (e.g. 25 kg per bag).');
      return;
    }

    submit.mutate({
      ...(form.id ? {} : { item_id: form.item_id }),
      supplier_item_code: form.supplier_item_code.trim() || null,
      supplier_item_name: form.supplier_item_name.trim() || null,
      price: price.toFixed(2),
      order_uom: form.order_uom.trim() || null,
      base_qty_per_order_unit: conversion || null,
      lead_time_days: lead,
      valid_until: form.valid_until || null,
    });
  };

  const listingData: PortalSupplierListing[] = listings.data?.data ?? [];

  const columns: Column<PortalSupplierListing>[] = [
    {
      key: 'item',
      header: 'Item',
      cell: (r) => (
        <StackedCell
          primary={<span className="font-mono">{r.item?.code ?? '—'}</span>}
          secondary={r.item?.name}
        />
      ),
    },
    {
      key: 'supplier_item_code',
      header: 'Your Part No.',
      cell: (r) => <span className="font-mono">{r.supplier_item_code ?? '—'}</span>,
    },
    {
      key: 'price',
      header: 'Price',
      align: 'right',
      cell: (r) => (
        <div>
          <NumCell className="font-medium">{formatPeso(r.price)}</NumCell>
          {r.order_uom && <div className="text-xs text-muted">per {r.order_uom}</div>}
        </div>
      ),
    },
    {
      key: 'lead_time_days',
      header: 'Lead Time',
      align: 'right',
      cell: (r) => <NumCell>{r.lead_time_days}d</NumCell>,
    },
    {
      key: 'valid_until',
      header: 'Valid Until',
      cell: (r) => <span className="font-mono">{r.valid_until ? formatDate(r.valid_until) : '—'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <div className="flex flex-col items-start gap-1">
          <Chip variant={chipVariantForStatus(r.status)}>{statusLabel[r.status]}</Chip>
          {r.status === 'rejected' && r.rejection_reason && (
            <span className="text-2xs text-danger-fg max-w-[16rem]">{r.rejection_reason}</span>
          )}
        </div>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      togglable: false,
      cell: (r) =>
        r.status === 'pending' ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            iconOnly
            icon={<LuPencil size={14} />}
            aria-label="Edit listing"
            onClick={() => startEdit(r)}
          />
        ) : null,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Item Listings"
        subtitle={
          listings.data
            ? `${listings.data.meta.total} offers submitted for review`
            : 'Offer your items and prices to Ogami for approval'
        }
        backTo="/portal/supplier"
        backLabel="Portal"
        actions={
          <Button
            variant="primary"
            size="sm"
            icon={showForm ? <LuX size={14} /> : <LuPlus size={14} />}
            onClick={() => (showForm ? setShowForm(false) : startCreate())}
          >
            {showForm ? 'Cancel' : 'New listing'}
          </Button>
        }
      />

      <div className="px-5 py-4 space-y-4">
        {showForm && (
          <Panel title={form.id ? 'Edit listing' : 'New listing'}>
            <form onSubmit={submitForm} className="space-y-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Select label="Ogami item" required value={form.item_id} disabled={!!form.id} onChange={(event) => setForm((current) => ({ ...current, item_id: event.target.value }))}>
                  <option value="">Select item…</option>
                  {catalog.isLoading && <option disabled>Loading items…</option>}
                  {(catalog.data ?? []).map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}
                </Select>
                {selected && (
                  <div className="text-sm text-muted self-end pb-2">
                    Priced against <span className="font-mono text-primary">{selected.code}</span> (base unit: {selected.unit_of_measure})
                  </div>
                )}
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Input label="Your part number" placeholder="e.g. MR-PP-88" value={form.supplier_item_code} onChange={(event) => setForm((current) => ({ ...current, supplier_item_code: event.target.value }))} maxLength={100} />
                <Input label="Your item name" placeholder="How you call this item" value={form.supplier_item_name} onChange={(event) => setForm((current) => ({ ...current, supplier_item_name: event.target.value }))} maxLength={255} />
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <Input label="Price (₱)" type="number" step="0.01" min="0.01" required placeholder="0.00" className="font-mono tabular-nums" value={form.price} onChange={(event) => setForm((current) => ({ ...current, price: event.target.value }))} />
                <Input label="Order unit" placeholder="e.g. bag, box" helper="Optional — the unit you quote in" value={form.order_uom} onChange={(event) => setForm((current) => ({ ...current, order_uom: event.target.value }))} maxLength={20} />
                <Input label="Units per order unit" type="number" step="0.0001" min="0.0001" placeholder={selected ? `e.g. 25 (${selected.unit_of_measure} per order unit)` : 'e.g. 25'} helper="Required if an order unit is set" value={form.base_qty_per_order_unit} onChange={(event) => setForm((current) => ({ ...current, base_qty_per_order_unit: event.target.value }))} />
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Input label="Lead time (days)" type="number" min="0" max="365" required placeholder="e.g. 12" value={form.lead_time_days} onChange={(event) => setForm((current) => ({ ...current, lead_time_days: event.target.value }))} />
                <Input label="Price valid until" type="date" helper="Optional — leave empty if the price does not expire" value={form.valid_until} onChange={(event) => setForm((current) => ({ ...current, valid_until: event.target.value }))} />
              </div>
              <div className="flex justify-end gap-2 pt-2 border-t border-default">
                <Button type="button" variant="secondary" size="sm" onClick={() => setShowForm(false)}>Cancel</Button>
                <Button type="submit" variant="primary" size="sm" loading={submit.isPending}>{form.id ? 'Save changes' : 'Submit for review'}</Button>
              </div>
            </form>
          </Panel>
        )}

        {listings.isLoading && !listings.data && <SkeletonTable columns={7} rows={6} />}

        {listings.isError && (
          <EmptyState
            icon="alert-circle"
            title="Could not load listings"
            action={<Button variant="secondary" onClick={() => listings.refetch()}>Retry</Button>}
          />
        )}

        {listings.data && (
          <DataTable
            tableKey="portal-supplier-item-listings"
            columns={columns}
            data={listingData}
            meta={listings.data.meta}
            onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
            emptyState={
              <EmptyState
                icon="package"
                title="No item listings yet"
                description="Submit your first item offer using the button above. Ogami purchasing will review it."
              />
            }
          />
        )}
      </div>
    </div>
  );
}
