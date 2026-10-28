import { RadialBarChart, RadialBar, PolarAngleAxis } from 'recharts';

interface Props {
 oee: number | null; // 0–1
 displayPolicy?: { world_class_ratio: number; on_track_ratio: number };
}

const colorFor = (v: number | null, policy?: Props['displayPolicy']) =>
 v == null ? 'var(--text-muted)' : policy ? (v >= policy.world_class_ratio ? 'var(--success)' : v >= policy.on_track_ratio ? 'var(--warning)' : 'var(--danger)') : 'var(--text-muted)';

export function OeeGaugeChart({ oee, displayPolicy }: Props) {
 const oeePct = oee == null ? null : oee * 100;
 return (
 <div className="flex justify-center">
 {/* Half-circle gauge */}
 <div className="relative" style={{ width: 200, height: 132 }}>
 <RadialBarChart
 width={200}
 height={132}
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
 isAnimationActive={false}
 />
 </RadialBarChart>
 {/* Center label */}
 <div className="absolute inset-x-0 bottom-0 flex flex-col items-center pb-1 pointer-events-none">
 <span className="text-2xl font-mono tabular-nums font-medium">{oeePct == null ? '—' : `${oeePct.toFixed(1)}%`}</span>
 <span className="text-2xs uppercase tracking-wider text-muted">OEE</span>
 </div>
 </div>

 </div>
 );
}
