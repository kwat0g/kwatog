import { useState } from 'react';
import { statutoryApi } from '@/api/payroll/statutory';

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

export default function StatutoryExportsPage() {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Statutory Filing Exports</h1>
        <p className="text-sm text-muted-foreground">
          Generate BIR, PhilHealth, and Pag-IBIG remittance files for finalized payroll periods.
        </p>
      </div>

      <div className="flex items-end gap-4">
        <label className="flex flex-col text-sm">
          Year
          <input
            type="number"
            className="mt-1 rounded-md border px-2 py-1 font-mono tabular-nums"
            value={year}
            onChange={(e) => setYear(Number(e.target.value))}
          />
        </label>
        <label className="flex flex-col text-sm">
          Month
          <select
            className="mt-1 rounded-md border px-2 py-1"
            value={month}
            onChange={(e) => setMonth(Number(e.target.value))}
          >
            {MONTHS.map((m, i) => (
              <option key={m} value={i + 1}>{m}</option>
            ))}
          </select>
        </label>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 max-w-2xl">
        <button
          className="rounded-md border px-4 py-3 text-left hover:bg-accent"
          onClick={() => statutoryApi.bir1601c(year, month)}
        >
          <div className="font-medium">BIR 1601-C</div>
          <div className="text-sm text-muted-foreground">Monthly WHT on compensation</div>
        </button>
        <button
          className="rounded-md border px-4 py-3 text-left hover:bg-accent"
          onClick={() => statutoryApi.philhealthRf1(year, month)}
        >
          <div className="font-medium">PhilHealth RF-1</div>
          <div className="text-sm text-muted-foreground">Monthly employer remittance</div>
        </button>
        <button
          className="rounded-md border px-4 py-3 text-left hover:bg-accent"
          onClick={() => statutoryApi.pagibigMcrf(year, month)}
        >
          <div className="font-medium">Pag-IBIG MCRF</div>
          <div className="text-sm text-muted-foreground">Monthly contribution remittance</div>
        </button>
        <button
          className="rounded-md border px-4 py-3 text-left hover:bg-accent"
          onClick={() => statutoryApi.bir1604cf(year)}
        >
          <div className="font-medium">BIR 1604-CF</div>
          <div className="text-sm text-muted-foreground">Annual return ({year})</div>
        </button>
      </div>
    </div>
  );
}
