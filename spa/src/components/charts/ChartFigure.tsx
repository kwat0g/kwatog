import type { ReactNode } from 'react';

interface ChartFigureProps {
  label: string;
  columns: string[];
  rows: Array<Array<string | number>>;
  children: ReactNode;
}

export function ChartFigure({ label, columns, rows, children }: ChartFigureProps) {
  return (
    <div className="tabular-nums">
      <div role="img" aria-label={label}>
        {children}
      </div>
      <div className="sr-only">
        <table aria-label={`${label} data`}>
          <thead>
            <tr>{columns.map((column) => <th key={column} scope="col">{column}</th>)}</tr>
          </thead>
          <tbody>
            {rows.map((row, rowIndex) => (
              <tr key={rowIndex}>
                {row.map((cell, cellIndex) => (
                  <td key={cellIndex}>{cell}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
