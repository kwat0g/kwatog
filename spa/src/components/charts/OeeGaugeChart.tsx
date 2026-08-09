import { RadialBarChart, RadialBar, PolarAngleAxis, ResponsiveContainer } from 'recharts';

interface Props {
 oee: number | null; // 0–1
 availability: number | null; // 0–1
 performance: number | null; // 0–1
 quality: number | null; // 0–1
 displayPolicy?: { world_class_ratio: number; on_track_ratio: number };
}

const colorFor = (v: number | null, policy?: Props['displayPolicy']) =>
 v == null ? 'var(--text-muted)' : policy ? (v >= policy.world_class_ratio ? 'var(--success)' : v >= policy.on_track_ratio ? 'var(--warning)' : 'var(--danger)') : 'var(--text-muted)';

export function OeeGaugeChart({ oee, availability, performance, quality, displayPolicy }: Props) {
 const oeePct = oee == null ? null : oee * 100;
 return (
 <div className="flex flex-col items-center gap-3">
 {/* Half-circle gauge */}
 <div className="relative" style={{ width: 200, height: 108 }}>
 <ResponsiveContainer width={200} height={200}>
 <RadialBarChart
 cx="50%"
 cy="100%"
 innerRadius="60%"
 outerRadius="100%"
 startAngle={180}
 endAngle={0}
 data={[{ value: oeePct ?? 0, fill: colorFor(oee, displayPolicy) }]}
 >
 <PolarAngleAxis type="number" domain={[0, 100]} angleAxisId={0} tick={false} />
 <RadialBar
 background={{ fill: 'var(--bg-elevated)' }}
 dataKey="value"
 angleAxisId={0}
 cornerRadius={4}
 />
 </RadialBarChart>
 </ResponsiveContainer>
 {/* Center label */}
 <div className="absolute inset-x-0 bottom-0 flex flex-col items-center pb-1 pointer-events-none">
 <span className="text-2xl font-mono tabular-nums font-medium">{oeePct == null ? '—' : `${oeePct.toFixed(1)}%`}</span>
 <span className="text-2xs uppercase tracking-wider text-muted">OEE</span>
 </div>
 </div>

 {/* A × P × Q breakdown */}
 <div className="grid grid-cols-3 gap-4 text-center text-sm w-full">
 <div>
 <div className="font-mono tabular-nums text-base font-medium" style={{ color: colorFor(availability, displayPolicy) }}>
 {availability == null ? '—' : `${(availability * 100).toFixed(1)}%`}
 </div>
 <div className="text-2xs text-muted mt-0.5">Availability</div>
 </div>
 <div>
 <div className="font-mono tabular-nums text-base font-medium" style={{ color: colorFor(performance, displayPolicy) }}>
 {performance == null ? '—' : `${(performance * 100).toFixed(1)}%`}
 </div>
 <div className="text-2xs text-muted mt-0.5">Performance</div>
 </div>
 <div>
 <div className="font-mono tabular-nums text-base font-medium" style={{ color: colorFor(quality, displayPolicy) }}>
 {quality == null ? '—' : `${(quality * 100).toFixed(1)}%`}
 </div>
 <div className="text-2xs text-muted mt-0.5">Quality</div>
 </div>
 </div>
 </div>
 );
}
