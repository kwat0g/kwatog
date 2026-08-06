import { useState } from 'react';
import { Download } from 'lucide-react';
import { statutoryApi } from '@/api/payroll/statutory';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';

const MONTHS = [
 'January', 'February', 'March', 'April', 'May', 'June',
 'July', 'August', 'September', 'October', 'November', 'December',
];

interface ExportCardProps {
 title: string;
 description: string;
 onClick: () => void;
}

function ExportCard({ title, description, onClick }: ExportCardProps) {
 return (
 <button
 type="button"
 onClick={onClick}
 className={cn('group flex items-start gap-3 text-left rounded-md border border-default bg-canvas px-4 py-3 transition-colors duration-fast hover:bg-subtle hover:border-strong cursor-pointer', focusRing)}
 >
 <span className="mt-0.5 text-muted group-hover:text-primary" aria-hidden>
 <Download size={15} />
 </span>
 <span className="min-w-0">
 <span className="block text-sm font-medium text-primary">{title}</span>
 <span className="block text-xs text-muted">{description}</span>
 </span>
 </button>
 );
}

export default function StatutoryExportsPage() {
 const now = new Date();
 const [year, setYear] = useState(now.getFullYear());
 const [month, setMonth] = useState(now.getMonth() + 1);

 return (
 <div>
 <PageHeader
 title="Statutory filing exports"
 subtitle="Generate BIR, PhilHealth, and Pag-IBIG remittance files for finalized payroll periods."
 />

 <div className="px-5 py-4 space-y-3">
 <Panel title="Filing period">
 <div className="flex flex-wrap items-end gap-3">
 <Input
 label="Year"
 type="number"
 value={year}
 onChange={(e) => setYear(Number(e.target.value))}
 className="font-mono tabular-nums"
 containerClassName="w-28"
 />
 <Select
 label="Month"
 value={month}
 onChange={(e) => setMonth(Number(e.target.value))}
 containerClassName="w-40"
 >
 {MONTHS.map((m, i) => (
 <option key={m} value={i + 1}>{m}</option>
 ))}
 </Select>
 </div>
 </Panel>

 <Panel title="Available files" meta={`${MONTHS[month - 1]} ${year}`}>
 <div className="grid gap-3 sm:grid-cols-2 max-w-3xl">
 <ExportCard
 title="BIR 1601-C"
 description="Monthly withholding tax on compensation"
 onClick={() => void statutoryApi.bir1601c(year, month)}
 />
 <ExportCard
 title="PhilHealth RF-1"
 description="Monthly employer remittance"
 onClick={() => void statutoryApi.philhealthRf1(year, month)}
 />
 <ExportCard
 title="Pag-IBIG MCRF"
 description="Monthly contribution remittance"
 onClick={() => void statutoryApi.pagibigMcrf(year, month)}
 />
 <ExportCard
 title="BIR 1604-CF"
 description={`Annual return for ${year}`}
 onClick={() => void statutoryApi.bir1604cf(year)}
 />
 </div>
 </Panel>
 </div>
 </div>
 );
}
