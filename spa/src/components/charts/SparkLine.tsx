interface SparkLineProps {
 data: number[];
  label: string;
 color?: string;
 height?: number;
 width?: number;
}

export function SparkLine({
 data,
  label,
 color = 'var(--accent)',
 height = 24,
 width = 80,
}: SparkLineProps) {
  const first = data[0];
  const last = data[data.length - 1];
  const minimum = data.length > 0 ? Math.min(...data) : 0;
  const span = data.length > 0 ? Math.max(...data) - minimum : 0;
  const direction = first === undefined || last === undefined
    ? 'no values'
    : last > first
      ? 'rising'
      : last < first
        ? 'falling'
        : 'unchanged';
  const summary = first === undefined || last === undefined
    ? `${label}: no values.`
    : `${label}: ${data.length} points, ${direction} from ${first} to ${last}.`;
  const points = data.map((value, index) => {
    const x = data.length === 1 ? width / 2 : 2 + (index / (data.length - 1)) * (width - 4);
    const y = span === 0 ? height / 2 : height - 2 - ((value - minimum) / span) * (height - 4);
    return `${x},${y}`;
  }).join(' ');

 return (
 <div role="img" aria-label={summary} className="inline-flex">
 <svg aria-hidden="true" width={width} height={height} viewBox={`0 0 ${width} ${height}`}>
  <polyline points={points} fill="none" stroke={color} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
 </svg>
 </div>
 );
}
