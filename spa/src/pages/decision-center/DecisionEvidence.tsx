import type { Recommendation } from '@/types/dss';
import { Panel } from '@/components/ui/Panel';
import { decisionView } from './presentation';

export default function DecisionEvidence({ recommendation }: { recommendation: Recommendation }) {
  const view = decisionView(recommendation);

  return (
    <section aria-label="Recorded evidence" className="min-w-0">
      <Panel title="Recorded evidence" meta={view.evidenceLabel}>
        {view.facts.length > 0 && (
          <dl className="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
            {view.facts.map(({ label, value }) => (
              <div key={label} className="min-w-0">
                <dt className="text-xs text-muted">{label}</dt>
                <dd className="break-words text-sm text-primary">{value}</dd>
              </div>
            ))}
          </dl>
        )}

        {view.tables.map((table) => (
          <div
            key={table.title}
            role="region"
            aria-label={`${table.title} table`}
            tabIndex={0}
            className="mt-3 min-w-0 overflow-x-auto rounded border border-default focus-visible:outline focus-visible:outline-2"
          >
            <table className="min-w-full border-collapse text-left text-xs text-primary">
              <caption className="px-3 py-2 text-left font-medium text-secondary">
                {table.title}
              </caption>
              <thead className="bg-canvas text-secondary">
                <tr>
                  {table.columns.map((column) => (
                    <th key={column} scope="col" className="border-t border-default px-3 py-2">
                      {column}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {table.rows.map((row, rowIndex) => (
                  <tr key={rowIndex} className="border-t border-default">
                    {row.map((value, columnIndex) => (
                      <td key={columnIndex} className="max-w-xs break-words px-3 py-2">
                        {value}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </Panel>
    </section>
  );
}
