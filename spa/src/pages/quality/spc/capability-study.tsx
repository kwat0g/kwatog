/**
 * SPC Capability Study page.
 *
 * Select a product + spec item, run the study, and view:
 * - Histogram with LSL/USL reference lines
 * - Cp/Cpk values with traffic-light chip
 * - Summary statistics (sample count, mean, std dev)
 */
import { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip as RechartsTooltip,
  ReferenceLine,
  ResponsiveContainer,
  Cell,
} from 'recharts';
import { spcApi } from '@/api/quality/spc';
import { inspectionSpecsApi } from '@/api/quality/inspectionSpecs';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import type { SpcCapabilityResult, RunCapabilityData } from '@/types/quality/spc';
import type { InspectionSpecItem } from '@/types/quality';

// ─── Cpk rating ────────────────────────────────────
function cpkRating(cpk: number): { label: string; variant: ChipVariant } {
  if (cpk >= 1.67) return { label: 'Excellent', variant: 'success' };
  if (cpk >= 1.33) return { label: 'Capable', variant: 'success' };
  if (cpk >= 1.0) return { label: 'Marginal', variant: 'warning' };
  return { label: 'Not capable', variant: 'danger' };
}

function cpRating(cp: number): { label: string; variant: ChipVariant } {
  if (cp >= 1.67) return { label: 'Excellent', variant: 'success' };
  if (cp >= 1.33) return { label: 'Capable', variant: 'success' };
  if (cp >= 1.0) return { label: 'Marginal', variant: 'warning' };
  return { label: 'Not capable', variant: 'danger' };
}

interface HistogramBar {
  label: string;
  count: number;
  binStart: number;
  binEnd: number;
  outsideSpec: boolean;
}

function buildHistogramBars(
  result: SpcCapabilityResult,
): HistogramBar[] {
  const { histogram } = result;
  if (!histogram?.bins || !histogram?.bin_edges) return [];

  return histogram.bins.map((count, i) => {
    const binStart = histogram.bin_edges[i];
    const binEnd = histogram.bin_edges[i + 1] ?? binStart;
    const midpoint = (binStart + binEnd) / 2;
    return {
      label: midpoint.toFixed(3),
      count,
      binStart,
      binEnd,
      outsideSpec: midpoint < histogram.lsl || midpoint > histogram.usl,
    };
  });
}

function HistogramTooltip({ active, payload }: { active?: boolean; payload?: Array<{ payload: HistogramBar }> }) {
  if (!active || !payload?.[0]) return null;
  const bar = payload[0].payload;
  return (
    <div className="bg-canvas border border-default rounded-md shadow-lg p-3 text-xs">
      <div className="font-mono tabular-nums">
        {bar.binStart.toFixed(4)} to {bar.binEnd.toFixed(4)}
      </div>
      <div className="mt-1">Count: <span className="font-medium">{bar.count}</span></div>
      {bar.outsideSpec && <div className="text-danger mt-0.5">Outside spec limits</div>}
    </div>
  );
}

export default function CapabilityStudyPage() {
  const [selectedProductId, setSelectedProductId] = useState('');
  const [selectedSpecItemId, setSelectedSpecItemId] = useState('');
  const [result, setResult] = useState<SpcCapabilityResult | null>(null);

  // Fetch products for the dropdown
  const { data: productsData } = useQuery({
    queryKey: ['crm', 'products', 'all'],
    queryFn: () => productsApi.list({ per_page: 200 }),
  });

  // Fetch the spec for the selected product to populate spec items dropdown
  const { data: spec } = useQuery({
    queryKey: ['quality', 'spec-for-product', selectedProductId],
    queryFn: () => inspectionSpecsApi.forProduct(selectedProductId),
    enabled: Boolean(selectedProductId),
  });

  // Filter to only bilateral (dimensional) spec items
  const specItems = useMemo(() => {
    if (!spec?.items) return [];
    return spec.items.filter(
      (item: InspectionSpecItem) =>
        item.tolerance_min !== null && item.tolerance_max !== null,
    );
  }, [spec]);

  // Run the study
  const study = useMutation({
    mutationFn: (data: RunCapabilityData) => spcApi.runCapability(data),
    onSuccess: (data) => {
      setResult(data);
      toast.success('Capability study completed');
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Study failed — check data availability.');
    },
  });

  const bars = useMemo(() => (result ? buildHistogramBars(result) : []), [result]);
  const cpkInfo = result ? cpkRating(result.cpk) : null;
  const cpInfo = result ? cpRating(result.cp) : null;

  const handleRunStudy = () => {
    if (!selectedProductId || !selectedSpecItemId) {
      toast.error('Select a product and spec item first.');
      return;
    }
    study.mutate({
      product_id: selectedProductId,
      spec_item_id: selectedSpecItemId,
    });
  };

  return (
    <div>
      <PageHeader
        title="Capability Study"
        subtitle="Compute Cp/Cpk indices for a product dimension"
        breadcrumbs={[
          { label: 'Quality', href: '/quality' },
          { label: 'SPC', href: '/quality/spc' },
          { label: 'Capability Study' },
        ]}
      />

      <div className="px-5 py-4 space-y-4">
        {/* ─── Input panel ─── */}
        <Panel title="Parameters">
          <div className="grid grid-cols-3 gap-4 items-end">
            <Select
              label="Product"
              value={selectedProductId}
              onChange={(e) => {
                setSelectedProductId(e.target.value);
                setSelectedSpecItemId('');
                setResult(null);
              }}
            >
              <option value="">Select product</option>
              {productsData?.data.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.part_number} -- {p.name}
                </option>
              ))}
            </Select>

            <Select
              label="Dimension (spec item)"
              value={selectedSpecItemId}
              onChange={(e) => {
                setSelectedSpecItemId(e.target.value);
                setResult(null);
              }}
              disabled={specItems.length === 0}
            >
              <option value="">
                {!selectedProductId
                  ? 'Select a product first'
                  : specItems.length === 0
                    ? 'No bilateral specs found'
                    : 'Select dimension'}
              </option>
              {specItems.map((item: InspectionSpecItem) => (
                <option key={item.id} value={item.id}>
                  {item.parameter_name}
                  {item.unit_of_measure ? ` (${item.unit_of_measure})` : ''}
                  {' '}[{item.tolerance_min} ... {item.tolerance_max}]
                </option>
              ))}
            </Select>

            <Button
              variant="primary"
              onClick={handleRunStudy}
              disabled={!selectedProductId || !selectedSpecItemId || study.isPending}
              loading={study.isPending}
            >
              {study.isPending ? 'Running...' : 'Run Study'}
            </Button>
          </div>
        </Panel>

        {/* ─── Results ─── */}
        {result && (
          <>
            {/* Stats row */}
            <div className="grid grid-cols-5 gap-4">
              <StatCard
                label="Cp"
                value={
                  <span className="font-mono tabular-nums text-xl">{result.cp.toFixed(2)}</span>
                }
              />
              <StatCard
                label="Cpk"
                value={
                  <div className="flex items-baseline gap-2">
                    <span className="font-mono tabular-nums text-xl">{result.cpk.toFixed(2)}</span>
                    {cpkInfo && <Chip variant={cpkInfo.variant}>{cpkInfo.label}</Chip>}
                  </div>
                }
              />
              <StatCard
                label="Sample count"
                value={<span className="font-mono tabular-nums text-xl">{result.sample_count}</span>}
              />
              <StatCard
                label="Mean"
                value={<span className="font-mono tabular-nums text-xl">{result.mean.toFixed(4)}</span>}
              />
              <StatCard
                label="Std Dev"
                value={<span className="font-mono tabular-nums text-xl">{result.std_dev.toFixed(4)}</span>}
              />
            </div>

            {/* Histogram */}
            <Panel
              title="Distribution"
              meta={`LSL: ${result.lsl} | USL: ${result.usl}`}
            >
              {bars.length > 0 ? (
                <ResponsiveContainer width="100%" height={350}>
                  <BarChart data={bars} margin={{ top: 10, right: 20, bottom: 25, left: 10 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border-default)" opacity={0.5} />
                    <XAxis
                      dataKey="label"
                      tick={{ fontSize: 10, fill: 'var(--text-muted)' }}
                      angle={-45}
                      textAnchor="end"
                      height={50}
                    />
                    <YAxis
                      tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
                      allowDecimals={false}
                    />
                    <RechartsTooltip content={<HistogramTooltip />} />

                    {/* LSL reference line */}
                    <ReferenceLine
                      x={result.lsl.toFixed(3)}
                      stroke="#ef4444"
                      strokeDasharray="6 3"
                      strokeWidth={2}
                      label={{ value: 'LSL', position: 'top', fontSize: 11, fill: '#ef4444' }}
                    />
                    {/* USL reference line */}
                    <ReferenceLine
                      x={result.usl.toFixed(3)}
                      stroke="#ef4444"
                      strokeDasharray="6 3"
                      strokeWidth={2}
                      label={{ value: 'USL', position: 'top', fontSize: 11, fill: '#ef4444' }}
                    />
                    {/* Mean reference line */}
                    <ReferenceLine
                      x={result.mean.toFixed(3)}
                      stroke="#6366f1"
                      strokeDasharray="4 2"
                      strokeWidth={1.5}
                      label={{ value: 'Mean', position: 'top', fontSize: 10, fill: '#6366f1' }}
                    />

                    <Bar dataKey="count" radius={[2, 2, 0, 0]}>
                      {bars.map((bar, index) => (
                        <Cell
                          key={`cell-${index}`}
                          fill={bar.outsideSpec ? '#fca5a5' : '#818cf8'}
                        />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <p className="text-sm text-muted">No histogram data available.</p>
              )}
            </Panel>

            {/* Detailed indices */}
            <Panel title="Capability indices detail">
              <div className="grid grid-cols-2 gap-4">
                <dl className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <dt className="text-muted">Cp (potential capability)</dt>
                    <dd className="font-mono tabular-nums">{result.cp.toFixed(3)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-muted">Cpk (actual capability)</dt>
                    <dd className="font-mono tabular-nums font-medium">{result.cpk.toFixed(3)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-muted">Cpu (upper capability)</dt>
                    <dd className="font-mono tabular-nums">{result.cpu.toFixed(3)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-muted">Cpl (lower capability)</dt>
                    <dd className="font-mono tabular-nums">{result.cpl.toFixed(3)}</dd>
                  </div>
                </dl>
                <div className="text-xs text-muted space-y-2">
                  <p>
                    <strong>IATF 16949 targets:</strong>
                  </p>
                  <ul className="list-disc list-inside space-y-1">
                    <li>Cpk &gt;= 1.67 for new product launch</li>
                    <li>Cpk &gt;= 1.33 for ongoing production</li>
                    <li>Cpk &lt; 1.0 requires immediate corrective action</li>
                  </ul>
                  <p className="mt-2">
                    Cp measures process spread vs spec width (centering ignored).
                    Cpk additionally accounts for centering -- a lower Cpk than Cp
                    indicates a shifted process mean.
                  </p>
                </div>
              </div>
            </Panel>
          </>
        )}
      </div>
    </div>
  );
}
