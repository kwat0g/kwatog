import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { RfqSupplierOption, RfqWrite } from '@/types/purchasing';

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ??
  (e instanceof Error ? e.message : fallback);

const DEFAULT_INSTRUCTIONS =
  'Quote the exact item and specification listed. Attach your quotation PDF and, for materials, the certificate of analysis.';

const pad = (n: number) => String(n).padStart(2, '0');

/** A Date as a `datetime-local` value in the browser's own time zone. */
const toLocalInput = (d: Date): string =>
  `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

/** Three working days from now at 17:00 local time. */
const defaultDeadline = (): string => {
  const d = new Date();
  let added = 0;
  while (added < 3) {
    d.setDate(d.getDate() + 1);
    if (d.getDay() !== 0 && d.getDay() !== 6) added += 1;
  }
  d.setHours(17, 0, 0, 0);
  return toLocalInput(d);
};

const REACH: Record<
  RfqSupplierOption['reach'],
  { label: string; variant: 'info' | 'neutral' | 'danger' }
> = {
  portal: { label: 'Portal', variant: 'info' },
  email: { label: 'Email only', variant: 'neutral' },
  none: { label: 'No contact', variant: 'danger' },
};

type Line = {
  id: string;
  description: string;
  quantity: string;
  unit: string | null;
  required: string;
};

/**
 * One page for creating (from a PR: `?purchase_request=`) and editing (draft
 * RFQ: `/:id/edit`) an RFQ. Create offers Save draft or Publish now.
 */
export default function CreateRfqPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { id: rfqId } = useParams<{ id?: string }>();
  const [params] = useSearchParams();
  const isEdit = !!rfqId;

  const rfq = useQuery({
    queryKey: ['purchasing', 'rfqs', rfqId],
    queryFn: () => rfqsApi.show(rfqId!),
    enabled: isEdit,
  });
  const prId = isEdit
    ? (rfq.data?.purchase_request?.id ?? '')
    : (params.get('purchase_request') ?? '');
  const pr = useQuery({
    queryKey: ['purchasing', 'purchase-requests', prId],
    queryFn: () => purchaseRequestsApi.show(prId),
    enabled: !isEdit && !!prId,
  });
  const suppliers = useQuery({
    queryKey: ['purchasing', 'purchase-requests', prId, 'rfq-setup'],
    queryFn: () => rfqsApi.setup(prId),
    enabled: !!prId,
  });
  const [supplierSearch, setSupplierSearch] = useState('');

  const [title, setTitle] = useState('');
  const [instructions, setInstructions] = useState(DEFAULT_INSTRUCTIONS);
  const [deadline, setDeadline] = useState(defaultDeadline);
  const [invited, setInvited] = useState<Record<string, boolean>>({});
  const [reasons, setReasons] = useState<Record<string, string>>({});
  const [dates, setDates] = useState<Record<string, string>>({});
  const [specs, setSpecs] = useState<Record<string, string>>({});
  const [seeded, setSeeded] = useState(false);

  // Seed the form once: from the draft RFQ when editing, from the PR when creating.
  useEffect(() => {
    if (seeded) return;
    if (isEdit && rfq.data) {
      setTitle(rfq.data.title);
      setInstructions(rfq.data.instructions ?? '');
      setDeadline(toLocalInput(new Date(rfq.data.closes_at)));
      setInvited(
        Object.fromEntries(
          rfq.data.invitations.filter((i) => i.vendor).map((i) => [i.vendor!.id, true]),
        ),
      );
      setReasons(
        Object.fromEntries(
          rfq.data.invitations
            .filter((i) => i.vendor && i.exception_reason)
            .map((i) => [i.vendor!.id, i.exception_reason!]),
        ),
      );
      setDates(
        Object.fromEntries(
          rfq.data.items.map((item) => [item.id, item.required_delivery_date ?? '']),
        ),
      );
      setSpecs(
        Object.fromEntries(rfq.data.items.map((item) => [item.id, item.specification ?? ''])),
      );
      setSeeded(true);
    } else if (!isEdit && pr.data) {
      const lines = pr.data.items ?? [];
      const more = lines.length > 1 ? ` +${lines.length - 1} more` : '';
      setTitle(`RFQ for ${pr.data.pr_number}: ${lines[0]?.description ?? ''}${more}`.slice(0, 200));
      setDates(
        Object.fromEntries(lines.map((line) => [line.id, pr.data.required_delivery_date ?? ''])),
      );
      setSeeded(true);
    }
  }, [seeded, isEdit, rfq.data, pr.data]);

  const lines: Line[] = isEdit
    ? (rfq.data?.items ?? []).map((item) => ({
        id: item.id,
        description: item.description,
        quantity: item.quantity,
        unit: item.unit,
        required: item.required_delivery_date ?? '',
      }))
    : // Only what is not yet on a purchase order is sourced.
      (suppliers.data?.lines ?? [])
        .filter((line) => Number(line.remaining_quantity) > 0)
        .map((line) => ({
          id: line.id,
          description: line.description,
          quantity: line.remaining_quantity,
          unit: line.unit,
          required: pr.data?.required_delivery_date ?? '',
        }));
  const unlinked = isEdit
    ? []
    : (suppliers.data?.lines ?? []).filter((line) => Number(line.remaining_quantity) > 0 && !line.has_item);
  const options = suppliers.data?.suppliers ?? [];
  const term = supplierSearch.trim().toLowerCase();
  const shown = options.filter((option) => !term || invited[option.id] || option.name.toLowerCase().includes(term));
  const chosen = options.filter((option) => invited[option.id]);
  const missingReason = chosen.some((option) => !option.qualified && !reasons[option.id]?.trim());
  const deadlineInPast = !deadline || new Date(deadline).getTime() <= Date.now();
  const ready =
    title.trim() !== '' && chosen.length > 0 && !missingReason && !deadlineInPast && unlinked.length === 0;

  const body = (): RfqWrite => ({
    title: title.trim(),
    instructions: instructions.trim() || null,
    // The input is local wall-clock time; convert through Date so the server
    // receives the right instant (not the local time mislabelled as UTC).
    closes_at: new Date(deadline).toISOString(),
    invitations: chosen.map((option) => ({
      vendor_id: option.id,
      exception_reason: option.qualified ? undefined : reasons[option.id]?.trim(),
    })),
    required_delivery_dates: Object.fromEntries(Object.entries(dates).filter(([, value]) => value)),
    specifications: Object.fromEntries(Object.entries(specs).filter(([, value]) => value.trim())),
  });

  const onSaved = async (id: string, message: string) => {
    await qc.invalidateQueries({ queryKey: ['purchasing', 'rfqs'] });
    if (prId) await qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests', prId] });
    toast.success(message);
    navigate(`/purchasing/rfqs/${id}`);
  };

  const create = useMutation({
    mutationFn: (publish: boolean) => rfqsApi.createFromPr(prId, { ...body(), publish }),
    onSuccess: (created, publish) =>
      onSaved(
        created.id,
        publish ? 'RFQ published to the invited suppliers.' : 'RFQ saved as a draft.',
      ),
    onError: (e) => toast.error(errMsg(e, 'The RFQ could not be saved.')),
  });
  const update = useMutation({
    mutationFn: () => rfqsApi.update(rfqId!, body()),
    onSuccess: (saved) => onSaved(saved.id, 'RFQ draft updated.'),
    onError: (e) => toast.error(errMsg(e, 'The RFQ could not be updated.')),
  });
  const pending = create.isPending || update.isPending;

  if (!isEdit && !prId) {
    return (
      <EmptyState
        icon="file-text"
        title="Start an RFQ from a purchase request"
        description="Open an approved purchase request and choose Start RFQ."
        action={
          <Button onClick={() => navigate('/purchasing/purchase-requests')}>
            Purchase requests
          </Button>
        }
      />
    );
  }
  if (rfq.isLoading || pr.isLoading || suppliers.isLoading)
    return <SkeletonTable columns={3} rows={6} />;
  if (rfq.isError || pr.isError || suppliers.isError || (isEdit ? !rfq.data : !pr.data)) {
    return (
      <EmptyState
        icon="alert-circle"
        title={isEdit ? 'Failed to load the RFQ' : 'Failed to load the purchase request'}
        action={
          <Button
            onClick={() => {
              void rfq.refetch();
              void pr.refetch();
              void suppliers.refetch();
            }}
          >
            Retry
          </Button>
        }
      />
    );
  }
  if (isEdit && rfq.data?.status !== 'draft') {
    return (
      <EmptyState
        icon="lock"
        title="Only draft RFQs can be edited"
        action={<Button onClick={() => navigate(`/purchasing/rfqs/${rfqId}`)}>Back to RFQ</Button>}
      />
    );
  }

  const prNumber = isEdit ? rfq.data?.purchase_request?.pr_number : pr.data?.pr_number;

  return (
    <div>
      <PageHeader
        title={isEdit ? 'Edit RFQ' : 'Start supplier RFQ'}
        subtitle={prNumber ? `From ${prNumber}` : undefined}
        backTo={isEdit ? `/purchasing/rfqs/${rfqId}` : `/purchasing/purchase-requests/${prId}`}
        backLabel={isEdit ? 'RFQ' : 'Purchase request'}
      />

      <div className="px-5 py-4 space-y-4 max-w-4xl">
        <Panel title="Details">
          <div className="space-y-3">
            <Input
              label="Title"
              value={title}
              maxLength={200}
              onChange={(e) => setTitle(e.target.value)}
            />
            <label className="block text-sm">
              <span className="block text-2xs uppercase tracking-wider text-muted mb-1">
                Instructions to suppliers
              </span>
              <textarea
                value={instructions}
                onChange={(e) => setInstructions(e.target.value)}
                className="w-full min-h-20 border border-default rounded-md bg-canvas p-3 text-sm"
              />
            </label>
            <Input
              label="Quotation deadline"
              type="datetime-local"
              value={deadline}
              onChange={(e) => setDeadline(e.target.value)}
              error={deadline && deadlineInPast ? 'Choose a time in the future.' : undefined}
            />
            <p className="text-xs text-muted">
              Prices stay sealed until the deadline. You can close early once every invited supplier
              has submitted.
            </p>
          </div>
        </Panel>

        <Panel title="Lines">
          {!isEdit && (
            <p className="text-sm text-muted mb-3">
              Only the quantity not yet on a purchase order is included.
            </p>
          )}
          {unlinked.length > 0 && (
            <p className="text-sm text-danger-fg mb-3" role="alert">
              Link {unlinked.map((line) => `"${line.description}"`).join(', ')} to an inventory item on the
              purchase request first — an awarded line becomes a purchase-order line, which needs an item.
            </p>
          )}
          <div className="space-y-4">
            {lines.map((line) => (
              <div key={line.id} className="border-b border-subtle pb-4 last:border-0 last:pb-0">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <span className="font-medium">{line.description}</span>
                  <span className="font-mono tabular-nums text-sm">
                    {line.quantity} {line.unit ?? ''}
                  </span>
                </div>
                <div className="mt-2 grid sm:grid-cols-2 gap-3">
                  <Input
                    label="Required delivery date"
                    type="date"
                    value={dates[line.id] ?? line.required}
                    onChange={(e) => setDates((cur) => ({ ...cur, [line.id]: e.target.value }))}
                  />
                  <Input
                    label="Specification (optional)"
                    value={specs[line.id] ?? ''}
                    onChange={(e) => setSpecs((cur) => ({ ...cur, [line.id]: e.target.value }))}
                    placeholder="Grade, colour, tolerance…"
                  />
                </div>
              </div>
            ))}
          </div>
        </Panel>

        <Panel title="Suppliers" meta={`${chosen.length} invited`}>
          {options.length === 0 ? (
            <p className="text-sm text-muted">
              No active suppliers. Add or reactivate a vendor first.
            </p>
          ) : (
            <div className="space-y-2">
              {options.length > 6 && (
                <Input
                  label="Find a supplier"
                  value={supplierSearch}
                  onChange={(e) => setSupplierSearch(e.target.value)}
                  placeholder="Type a name — qualified suppliers are listed first"
                />
              )}
              {shown.map((option) => (
                <div key={option.id} className="rounded-md border border-default p-3">
                  <label className="flex items-start gap-3 cursor-pointer">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={!!invited[option.id]}
                      onChange={(e) =>
                        setInvited((cur) => ({ ...cur, [option.id]: e.target.checked }))
                      }
                    />
                    <span className="flex-1 min-w-0">
                      <span className="block font-medium">{option.name}</span>
                      <span className="mt-1 flex flex-wrap gap-1">
                        <Chip variant={option.qualified ? 'success' : 'warning'}>
                          {option.qualified ? 'Qualified' : 'Not qualified'}
                        </Chip>
                        <Chip variant={REACH[option.reach].variant}>
                          {REACH[option.reach].label}
                        </Chip>
                        {option.lead_time_days !== null && (
                          <span className="text-xs text-muted font-mono tabular-nums">
                            {option.lead_time_days} d lead time
                          </span>
                        )}
                      </span>
                    </span>
                  </label>
                  {invited[option.id] && !option.qualified && (
                    <Input
                      className="mt-2"
                      label="Reason for inviting a non-qualified supplier"
                      value={reasons[option.id] ?? ''}
                      onChange={(e) =>
                        setReasons((cur) => ({ ...cur, [option.id]: e.target.value }))
                      }
                      error={!reasons[option.id]?.trim() ? 'Required' : undefined}
                    />
                  )}
                  {invited[option.id] && option.reach === 'none' && (
                    <p className="mt-2 text-xs text-warning-fg">
                      No portal account or email on file — enter their quotation manually once the
                      RFQ is open.
                    </p>
                  )}
                </div>
              ))}
            </div>
          )}
        </Panel>

        <div className="flex flex-wrap justify-end gap-2">
          <Button
            variant="secondary"
            disabled={pending}
            onClick={() =>
              navigate(
                isEdit ? `/purchasing/rfqs/${rfqId}` : `/purchasing/purchase-requests/${prId}`,
              )
            }
          >
            Cancel
          </Button>
          {isEdit ? (
            <Button
              variant="primary"
              disabled={!ready || pending}
              loading={update.isPending}
              onClick={() => update.mutate()}
            >
              {update.isPending ? 'Saving…' : 'Save changes'}
            </Button>
          ) : (
            <>
              <Button
                variant="secondary"
                disabled={!ready || pending}
                loading={create.isPending && create.variables === false}
                onClick={() => create.mutate(false)}
              >
                Save draft
              </Button>
              <Button
                variant="primary"
                disabled={!ready || pending}
                loading={create.isPending && create.variables === true}
                onClick={() => create.mutate(true)}
              >
                {create.isPending && create.variables === true ? 'Publishing…' : 'Publish now'}
              </Button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
