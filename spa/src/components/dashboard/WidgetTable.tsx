import { cn } from '@/lib/cn';
import type { WidgetTableData } from '@/api/dashboard-layout';

/**
 * A table widget: the few rows that need acting on, not a full list page.
 *
 * Deliberately NOT the shared DataTable — that primitive carries sorting,
 * density, selection and pagination chrome, none of which fits inside a
 * dashboard tile. `total_count` names what the tile is not showing so a
 * truncated list never reads as the whole set.
 */
export function WidgetTable({ columns, rows, total_count }: WidgetTableData) {
  if (rows.length === 0) {
    return <p className="text-xs text-muted">Nothing outstanding.</p>;
  }

  return (
    <div className="space-y-2">
      <table className="w-full text-xs">
        <thead>
          <tr className="border-b border-subtle text-left">
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                className={cn(
                  'pb-1 font-medium text-muted',
                  column.align === 'right' || column.numeric ? 'text-right' : column.align === 'center' ? 'text-center' : 'text-left',
                )}
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-subtle">
          {rows.map((row, index) => (
            <tr key={index}>
              {columns.map((column) => {
                const cell = row[column.key];

                return (
                  <td
                    key={column.key}
                    className={cn(
                      'py-1.5 text-primary',
                      column.align === 'right' || column.numeric ? 'text-right font-mono tabular-nums' : column.align === 'center' ? 'text-center' : 'text-left',
                    )}
                  >
                    {cell === null || cell === '' ? '—' : cell}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>

      {total_count > rows.length && (
        <p className="text-2xs text-subtle">
          Showing {rows.length} of {total_count.toLocaleString()}
        </p>
      )}
    </div>
  );
}
