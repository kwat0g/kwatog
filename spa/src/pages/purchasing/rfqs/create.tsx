import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { vendorsApi } from '@/api/accounting/vendors';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';

export default function CreateRfqPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const prId = params.get('purchase_request') ?? '';
  const [step, setStep] = useState(1);
  const [highestStep, setHighestStep] = useState(1);
  const [title, setTitle] = useState('Production resin sourcing');
  const [instructions, setInstructions] = useState(
    'Quote the exact material and grade shown below. Substitutions are not permitted.',
  );
  const [deadline, setDeadline] = useState(() =>
    new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 16),
  );
  const [selected, setSelected] = useState<Record<string, string>>({});
  const [reasons, setReasons] = useState<Record<string, string>>({});
  const [requiredDates, setRequiredDates] = useState<Record<string, string>>({});
  const [extraPicks, setExtraPicks] = useState<Array<{ id: string; name: string }>>([]);
  const pr = useQuery({
    queryKey: ['purchasing', 'purchase-requests', prId],
    queryFn: () => purchaseRequestsApi.show(prId),
    enabled: !!prId,
  });
  const sourcing = useQuery({
    queryKey: ['purchasing', 'purchase-requests', prId, 'sourcing'],
    queryFn: () => purchaseRequestsApi.sourcing(prId),
    enabled: !!prId,
  });
  const vendors = useQuery({
    queryKey: ['accounting', 'vendors', 'rfq-invite'],
    queryFn: () => vendorsApi.list({ per_page: 200, is_active: 'true' }),
    enabled: step === 2,
  });
  const create = useMutation({
    mutationFn: () =>
      rfqsApi.createFromPr(prId, {
        title,
        instructions,
        closes_at: new Date(deadline).toISOString(),
        invitations: [
          ...Object.values(selected)
            .filter(Boolean)
            .map((vendor_id) => ({ vendor_id, exception_reason: reasons[vendor_id] || undefined })),
          ...extraPicks.map((pick) => ({
            vendor_id: pick.id,
            exception_reason: reasons[pick.id] || undefined,
          })),
        ],
        allow_partial_quantity: Object.fromEntries(
          (pr.data?.items ?? []).map((item) => [item.id, true]),
        ),
        required_delivery_dates: requiredDates,
      }),
    onSuccess: (rfq) => {
      toast.success('RFQ draft created.');
      navigate(`/purchasing/rfqs/${rfq.id}`);
    },
    onError: () =>
      toast.error('Could not create the RFQ. Check the supplier selection and deadline.'),
  });
  if (!prId)
    return (
      <Panel title="Start RFQ">
        <p className="text-muted">Open an approved purchase request and choose Start RFQ.</p>
      </Panel>
    );
  if (pr.isLoading) return <div className="p-5 text-muted">Loading purchase request…</div>;
  if (!pr.data)
    return (
      <Panel title="Purchase request unavailable">
        <p className="text-muted">The source purchase request could not be loaded.</p>
      </Panel>
    );
  const sourcingLines = sourcing.data?.lines ?? [];
  const candidates = sourcingLines.flatMap((line) => line.candidates);
  const unique = Array.from(
    new Map(candidates.map((candidate) => [candidate.id, candidate])).values(),
  ).map((candidate) => ({
    ...candidate,
    qualified: sourcingLines.every((line) =>
      line.candidates.some(
        (lineCandidate) => lineCandidate.id === candidate.id && lineCandidate.qualified,
      ),
    ),
  }));
  const canAdvance = () => {
    if (step === 1) return title.trim().length > 0 && deadline !== '';
    if (step === 2)
      return (
        (Object.values(selected).some(Boolean) || extraPicks.length > 0) &&
        Object.values(selected)
          .filter(Boolean)
          .every((vendorId) => {
            const candidate = unique.find((row) => row.id === vendorId);
            return candidate?.qualified || !!reasons[vendorId]?.trim();
          }) &&
        extraPicks.every((pick) => !!reasons[pick.id]?.trim())
      );
    if (step === 3) return new Date(deadline).getTime() > Date.now();
    return true;
  };
  const next = () => {
    if (!canAdvance()) {
      toast.error('Complete the required RFQ fields before continuing.');
      return;
    }
    setStep((current) => Math.min(4, current + 1));
    setHighestStep((current) => Math.max(current, Math.min(4, step + 1)));
  };
  return (
    <div>
      <PageHeader
        title="Start supplier RFQ"
        subtitle={`From ${pr.data.pr_number}`}
        backTo={`/purchasing/purchase-requests/${prId}`}
        backLabel="Purchase request"
      />
      <div className="px-5 py-4 space-y-4 max-w-4xl">
        <div className="flex flex-wrap items-center gap-2 text-xs uppercase tracking-wider text-muted">
          {['Requirements', 'Suppliers', 'Terms and documents', 'Review and publish'].map(
            (label, index) => (
              <button
                key={label}
                disabled={index + 1 > highestStep}
                className={`px-2 py-1 rounded disabled:opacity-50 ${step === index + 1 ? 'bg-accent text-accent-fg' : 'bg-subtle'}`}
                onClick={() => setStep(index + 1)}
              >
                {index + 1}. {label}
              </button>
            ),
          )}
        </div>
        {step === 1 && (
          <Panel title="Requirements">
            <div className="space-y-3">
              <Input label="RFQ title" value={title} onChange={(e) => setTitle(e.target.value)} />
              <Input
                label="Instructions"
                value={instructions}
                onChange={(e) => setInstructions(e.target.value)}
              />
              <div className="rounded border border-default p-3">
                <div className="text-2xs uppercase tracking-wider text-muted mb-2">PR snapshot</div>
                {pr.data.items?.map((item) => (
                  <div
                    key={item.id}
                    className="flex flex-wrap justify-between gap-2 border-b border-subtle py-2 last:border-0"
                  >
                    <span>{item.description}</span>
                    <span className="font-mono">
                      {item.quantity} {item.unit ?? ''}
                    </span>
                    <Input
                      aria-label={`Required delivery date for ${item.description}`}
                      className="max-w-48"
                      type="date"
                      value={requiredDates[item.id] ?? pr.data.required_delivery_date ?? ''}
                      onChange={(e) =>
                        setRequiredDates((current) => ({ ...current, [item.id]: e.target.value }))
                      }
                    />
                  </div>
                ))}
              </div>
            </div>
          </Panel>
        )}
        {step === 2 && (
          <Panel title="Invite suppliers">
            <p className="text-sm text-muted mb-3">
              Qualified suppliers are suggested first. Select any number; non-qualified invitations
              require a documented exception reason.
            </p>
            {unique.length === 0 && !sourcing.isLoading && (
              <p className="text-sm text-muted mb-3">
                No approved, listed or past suppliers for these items yet. Invite any active
                supplier below with an exception reason.
              </p>
            )}
            <div className="grid sm:grid-cols-2 gap-2">
              {unique.map((candidate) => (
                <div key={candidate.id} className="rounded border border-default p-3">
                  <label className="flex gap-3 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={Object.values(selected).includes(candidate.id)}
                      onChange={(e) =>
                        setSelected((current) => ({
                          ...current,
                          [candidate.id]: e.target.checked ? candidate.id : '',
                        }))
                      }
                    />
                    <span>
                      <span className="block font-medium">{candidate.name}</span>
                      <span className="text-xs text-muted">
                        {candidate.qualified ? 'Qualified supplier' : 'Exception review required'}
                        {candidate.lead_time_days ? ` · ${candidate.lead_time_days} days` : ''}
                      </span>
                    </span>
                  </label>
                  {!candidate.qualified && Object.values(selected).includes(candidate.id) && (
                    <Input
                      className="mt-2"
                      label="Exception reason"
                      value={reasons[candidate.id] ?? ''}
                      onChange={(e) =>
                        setReasons((current) => ({ ...current, [candidate.id]: e.target.value }))
                      }
                    />
                  )}
                </div>
              ))}
              {extraPicks.map((pick) => (
                <div key={pick.id} className="rounded border border-default p-3">
                  <label className="flex gap-3 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={true}
                      onChange={(e) =>
                        !e.target.checked &&
                        setExtraPicks((current) => current.filter((p) => p.id !== pick.id))
                      }
                    />
                    <span>
                      <span className="block font-medium">{pick.name}</span>
                      <span className="text-xs text-muted">Exception review required</span>
                    </span>
                  </label>
                  <Input
                    className="mt-2"
                    label="Exception reason"
                    value={reasons[pick.id] ?? ''}
                    onChange={(e) =>
                      setReasons((current) => ({ ...current, [pick.id]: e.target.value }))
                    }
                  />
                </div>
              ))}
            </div>
            <div className="mt-4 space-y-2">
              <Select
                label="Invite another supplier"
                value=""
                onChange={(e) => {
                  if (e.target.value) {
                    const vendor = vendors.data?.data?.find((v) => v.id === e.target.value);
                    if (
                      vendor &&
                      !extraPicks.some((p) => p.id === vendor.id) &&
                      !unique.some((c) => c.id === vendor.id)
                    ) {
                      setExtraPicks((current) => [
                        ...current,
                        { id: vendor.id, name: vendor.name },
                      ]);
                      e.target.value = '';
                    }
                  }
                }}
              >
                <option value="">Select a vendor</option>
                {vendors.data?.data
                  ?.filter(
                    (v) =>
                      !extraPicks.some((p) => p.id === v.id) && !unique.some((c) => c.id === v.id),
                  )
                  .map((vendor) => (
                    <option key={vendor.id} value={vendor.id}>
                      {vendor.name}
                    </option>
                  ))}
              </Select>
            </div>
          </Panel>
        )}
        {step === 3 && (
          <Panel title="Terms and documents">
            <Input
              label="Submission deadline"
              type="datetime-local"
              value={deadline}
              onChange={(e) => setDeadline(e.target.value)}
            />
            <p className="text-sm text-muted mt-3">
              Supplier quotations must include structured commercial values and a formal quotation
              PDF. Requirement documents can be added after the draft is created.
            </p>
          </Panel>
        )}
        {step === 4 && (
          <Panel title="Review and publish">
            <dl className="grid sm:grid-cols-2 gap-3 text-sm">
              <div>
                <dt className="text-muted">Title</dt>
                <dd>{title}</dd>
              </div>
              <div>
                <dt className="text-muted">Deadline</dt>
                <dd className="font-mono">{deadline}</dd>
              </div>
              <div>
                <dt className="text-muted">Source estimate</dt>
                <dd className="font-mono">{formatPeso(pr.data.total_estimated_amount)}</dd>
              </div>
              <div>
                <dt className="text-muted">Suppliers</dt>
                <dd>{Object.values(selected).filter(Boolean).length + extraPicks.length}</dd>
              </div>
            </dl>
            <p className="mt-4 text-sm text-muted">
              Publishing remains a separate buyer action on the RFQ detail page. Prices stay sealed
              until the server closes the event.
            </p>
          </Panel>
        )}
        <div className="flex justify-between">
          <Button
            variant="secondary"
            onClick={() => navigate(`/purchasing/purchase-requests/${prId}`)}
          >
            Cancel
          </Button>
          <div className="flex gap-2">
            {step > 1 && (
              <Button variant="secondary" onClick={() => setStep(step - 1)}>
                Back
              </Button>
            )}
            {step < 4 ? (
              <Button
                variant="primary"
                onClick={next}
                disabled={sourcing.isLoading || sourcing.isError}
              >
                Next
              </Button>
            ) : (
              <Button
                variant="primary"
                onClick={() => create.mutate()}
                loading={create.isPending}
                disabled={
                  sourcing.isLoading ||
                  sourcing.isError ||
                  (!Object.values(selected).some(Boolean) && extraPicks.length === 0)
                }
              >
                Create draft
              </Button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
