import { useState, type ChangeEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { Upload, AlertCircle, CheckCircle2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { attendancesApi, type ImportResult } from '@/api/attendance/attendances';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';

export default function AttendanceImportPage() {
  const navigate = useNavigate();
  const [file, setFile] = useState<File | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);

  const mutation = useMutation({
    mutationFn: (f: File) => attendancesApi.import(f),
    onSuccess: (r) => {
      setResult(r);
      if (r.imported > 0) toast.success(`Imported ${r.imported} records.`);
      if (r.skipped > 0) toast.error(`${r.skipped} rows had errors — see details below.`);
    },
    onError: () => toast.error('Import failed. Check the file format.'),
  });

  const onPick = (e: ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0];
    if (f) setFile(f);
  };

  const onDrop = (e: React.DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setDragOver(false);
    const f = e.dataTransfer.files?.[0];
    if (f) setFile(f);
  };

  return (
    <div>
      <PageHeader
        title="Import biometric DTR"
        subtitle="CSV columns required: employee_no, date, time_in, time_out"
        backTo="/hr/attendance"
        backLabel="Attendance"
      />
      <div className="max-w-3xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Upload file">
          <div
            onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
            onDragLeave={() => setDragOver(false)}
            onDrop={onDrop}
            className={
              'border-2 border-dashed rounded-md flex flex-col items-center justify-center py-12 transition-colors ' +
              (dragOver ? 'border-accent bg-elevated' : 'border-default')
            }
          >
            <Upload size={28} className="text-muted mb-3" />
            <p className="text-sm font-medium">
              {file ? file.name : 'Drop CSV here, or click to browse'}
            </p>
            {file && <p className="text-xs text-muted mt-1">{(file.size / 1024).toFixed(1)} KB</p>}
            <input
              type="file"
              accept=".csv,text/csv"
              onChange={onPick}
              className="absolute opacity-0 cursor-pointer w-full h-full"
            />
            <Button variant="secondary" size="sm" className="mt-3" onClick={() => document.getElementById('csv-input')?.click()}>
              Browse files
            </Button>
            <input
              id="csv-input"
              type="file"
              accept=".csv,text/csv"
              className="hidden"
              onChange={onPick}
            />
          </div>
          <div className="flex justify-end gap-2 pt-3 mt-3 border-t border-default">
            <Button variant="secondary" onClick={() => navigate('/hr/attendance')}>Cancel</Button>
            <Button
              variant="primary"
              disabled={!file || mutation.isPending}
              loading={mutation.isPending}
              onClick={() => file && mutation.mutate(file)}
            >
              {mutation.isPending ? 'Uploading…' : 'Import'}
            </Button>
          </div>
        </Panel>

        {result && (
          <Panel title="Import summary">
            <div className="grid grid-cols-3 gap-3 mb-4">
              <Stat label="Total" value={result.total} />
              <Stat label="Imported" value={result.imported} variant="success" />
              <Stat label="Skipped" value={result.skipped} variant={result.skipped > 0 ? 'danger' : 'neutral'} />
            </div>
            {result.errors.length > 0 && (
              <div className="border border-danger-bg rounded-md overflow-hidden">
                <div className="px-3 py-2 bg-danger-bg text-danger-fg text-xs uppercase tracking-wider font-medium flex items-center gap-1.5">
                  <AlertCircle size={12} />
                  {result.errors.length} error{result.errors.length === 1 ? '' : 's'}
                </div>
                <ul className="divide-y divide-subtle text-sm">
                  {result.errors.map((err, i) => (
                    <li key={i} className="px-3 py-2 flex items-start gap-2">
                      <span className="font-mono text-xs text-muted">Row {err.row}</span>
                      <span className="flex-1">{err.message}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {result.imported > 0 && result.skipped === 0 && (
              <div className="text-sm text-success-fg flex items-center gap-1.5">
                <CheckCircle2 size={14} />
                All rows imported successfully.
              </div>
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}

function Stat({ label, value, variant = 'neutral' }: { label: string; value: number; variant?: 'success' | 'danger' | 'neutral' }) {
  const colour = variant === 'success' ? 'text-success-fg' : variant === 'danger' ? 'text-danger-fg' : 'text-primary';
  return (
    <div className="p-3 border border-default rounded-md bg-surface">
      <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1">{label}</div>
      <div className={`text-2xl font-medium font-mono tabular-nums ${colour}`}>{value.toLocaleString()}</div>
    </div>
  );
}
