import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { vendorsApi } from '@/api/accounting/vendors';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatPeso } from '@/lib/formatNumber';
import { reportMutationError } from '@/lib/formErrors';
import type { PurchaseRequest, SourcingLine } from '@/types/purchasing';

interface Props {
  purchaseRequest: PurchaseRequest | null;
  onClose: () => void;
}

/**
 * The one PR → PO conversion surface, shared by the PR list and the PR detail.
 *
 * A PO always has exactly one vendor, so a multi-vendor PR becomes one PO per
 * vendor: each line is assigned independently and the backend groups them.
 * Lines may be left unassigned — that is a partial conversion, and the
 * remainder stays on the PR for a later pass. Qualified ASL suppliers are
 * offered first; picking a vendor that is not approved for the item is
 * allowed but shown as a warning (it will be recorded provisionally).
 */
export function ConvertPrToPoModal({ purchaseRequest, onClose }: Props) {
  const nav = useNavigate();
  const qc = useQueryClient();
  const prId = purchaseRequest?.id ?? null;
  const [vendorMap, setVendorMap] = useState<Record<string, string>>({});
  const [expectedDeliveryDate, setExpectedDeliveryDate] = useState('');

  useEffect(() => {
    setVendorMap({});
    setExpectedDeliveryDate('');
  }, [prId]);

  const sourcing = useQuery({
    queryKey: ['purchasing', 'purchase-requests', prId, 'sourcing'],
    queryFn: () => purchaseRequestsApi.sourcing(prId!),
    enabled: !!prId,
  });
  const vendors = useQuery({
    queryKey: ['accounting', 'vendors', 'pr-conversion'],
    queryFn: () => vendorsApi.list({ per_page: 200, is_active: 'true' }),
    enabled: !!prId,
  });

  const lines = useMemo(() => sourcing.data?.lines ?? [], [sourcing.data]);
  const effective = useMemo(() => {
    const map: Record<string, string> = {};
    for (const line of lines) {
      map[line.id] = vendorMap[line.id] ?? line.suggested_vendor_id ?? '';
    }
    return map;
  }, [lines, vendorMap]);

  const assignedCount = lines.filter((line) => effective[line.id]).length;
  const remaining = lines.length - assignedCount;

  const convert = useMutation({
    mutationFn: () => {
      const map: Record<string, string> = {};
      for (const [lineId, vendorId] of Object.entries(effective)) {
        if (vendorId) map[lineId] = vendorId;
      }
      return purchaseRequestsApi.convert(prId!, map, expectedDeliveryDate || undefined);
    },
    onSuccess: (orders) => {
      qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests'] });
      qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-orders'] });
      onClose();
      const count = orders.length;
      toast.success(`${count} purchase order${count === 1 ? '' : 's'} created.`);
      if (count === 1) nav(`/purchasing/purchase-orders/${orders[0].id}`);
      else nav('/purchasing/purchase-orders');
    },
    onError: (e) => reportMutationError(e, 'Failed to convert PR.'),
  });

  const allVendors = vendors.data?.data ?? [];
  const candidateIds = (line: SourcingLine) => new Set(line.candidates.map((c) => c.id));
  const qualifiedIds = (line: SourcingLine) =>
    new Set(line.candidates.filter((c) => c.qualified).map((c) => c.id));

  return (
    <Modal
      isOpen={!!purchaseRequest}
      onClose={onClose}
      title={`Convert ${purchaseRequest?.pr_number ?? 'PR'} to purchase order`}
      size="lg"
    >
      {sourcing.isLoading ? (
        <div className="py-4">
          <SkeletonTable rows={3} columns={4} />
        </div>
      ) : (
        <div className="py-4 space-y-3">
          <p className="text-sm text-muted">
            Assign a supplier per line. Each vendor becomes its own purchase order. Lines left
            unassigned stay on the request and can be converted later.
          </p>
          <Input
            label="Required delivery date"
            type="date"
            value={expectedDeliveryDate}
            onChange={(event) => setExpectedDeliveryDate(event.target.value)}
            helper="The date OGAMI needs the goods; the supplier may propose a different confirmed date."
            containerClassName="max-w-xs"
          />
          {lines.map((line) => {
            const chosen = effective[line.id] ?? '';
            const qualified = !chosen || qualifiedIds(line).has(chosen);
            return (
              <div
                key={line.id}
                className="grid grid-cols-1 sm:grid-cols-[1fr_110px_240px] gap-3 items-end border-b border-subtle pb-3"
              >
                <div>
                  <div className="font-medium text-sm">
                    {line.item?.code ?? 'Uncoded item'} · {line.description}
                  </div>
                  <div className="text-xs text-muted">
                    {line.quantity} {line.unit ?? line.item?.unit_of_measure ?? '—'} · est{' '}
                    {formatPeso(line.estimated_unit_price)}
                  </div>
                  {!qualified && (
                    <div className="text-xs text-warning-fg mt-0.5">
                      Not an approved supplier for this item — recorded provisionally.
                    </div>
                  )}
                </div>
                <div className="text-xs text-muted">{formatPeso(line.estimated_total)}</div>
                <Select
                  label="Supplier"
                  value={chosen}
                  onChange={(event) =>
                    setVendorMap((current) => ({ ...current, [line.id]: event.target.value }))
                  }
                >
                  <option value="">Leave unconverted</option>
                  {line.candidates.length > 0 && (
                    <optgroup label="Suggested">
                      {line.candidates.map((candidate) => (
                        <option key={`s-${candidate.id}`} value={candidate.id}>
                          {candidate.name ?? 'Vendor'}
                          {candidate.qualified ? ' ✓' : ''}
                          {candidate.unit_price ? ` · ${formatPeso(candidate.unit_price)}` : ''}
                        </option>
                      ))}
                    </optgroup>
                  )}
                  <optgroup label="All vendors">
                    {allVendors
                      .filter((vendor) => !candidateIds(line).has(vendor.id))
                      .map((vendor) => (
                        <option key={`a-${vendor.id}`} value={vendor.id}>
                          {vendor.name}
                        </option>
                      ))}
                  </optgroup>
                </Select>
              </div>
            );
          })}
          {remaining > 0 && assignedCount > 0 && (
            <div className="text-xs text-muted">
              {remaining} line{remaining === 1 ? '' : 's'} will remain unconverted.
            </div>
          )}
          <ModalFooter>
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={convert.isPending}
              disabled={assignedCount === 0 || convert.isPending}
              onClick={() => convert.mutate()}
            >
              {remaining > 0 && assignedCount > 0 ? (
                <>
                  Convert {assignedCount} line{assignedCount === 1 ? '' : 's'}{' '}
                  <Chip variant="warning" className="ml-1">partial</Chip>
                </>
              ) : (
                `Create purchase order${assignedCount === 1 ? '' : 's'}`
              )}
            </Button>
          </ModalFooter>
        </div>
      )}
    </Modal>
  );
}
