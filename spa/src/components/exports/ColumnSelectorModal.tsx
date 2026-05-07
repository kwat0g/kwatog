/**
 * Series E (E2) — column-selection UX before triggering a CSV/Excel
 * export. Replaces the immediate "Export" button on every list page so
 * users can opt into extra columns and persist their choice server-side.
 */

import { useEffect, useMemo, useState } from 'react';
import { useQuery, useMutation } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { exportsApi } from '@/api/exports';
import type { ExportFormat } from '@/types/exports';

interface ColumnSelectorModalProps {
  isOpen: boolean;
  onClose: () => void;
  module: string;
  filters?: Record<string, unknown>;
  defaultFormat?: ExportFormat;
}

export function ColumnSelectorModal({
  isOpen,
  onClose,
  module,
  filters,
  defaultFormat = 'xlsx',
}: ColumnSelectorModalProps) {
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [format, setFormat] = useState<ExportFormat>(defaultFormat);
  const [saveDefaults, setSaveDefaults] = useState(true);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['exports.columns', module],
    queryFn: () => exportsApi.columns(module),
    enabled: isOpen,
  });

  // Seed the selection state from the API response on open.
  useEffect(() => {
    if (data) setSelected(new Set(data.selected));
  }, [data]);

  const saveMutation = useMutation({
    mutationFn: (cols: string[]) => exportsApi.saveColumns(module, cols),
  });

  const ordered = useMemo(() => {
    if (!data) return [];
    // Preserve the registry's order; the user only toggles inclusion.
    return data.columns;
  }, [data]);

  const toggle = (key: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const handleDownload = async () => {
    const cols = ordered.map((c) => c.key).filter((k) => selected.has(k));
    if (cols.length === 0) {
      toast.error('Select at least one column to export.');
      return;
    }
    if (saveDefaults) {
      try {
        await saveMutation.mutateAsync(cols);
      } catch {
        toast.error('Could not save column preference, downloading anyway.');
      }
    }
    exportsApi.download(module, { format, columns: cols, filters });
    toast.success(`Generating ${format.toUpperCase()} export…`);
    onClose();
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="md" title="Export options">
      {isLoading && (
        <div className="text-sm text-muted py-6 text-center">Loading columns…</div>
      )}
      {isError && (
        <div className="text-sm text-danger py-6 text-center">
          Failed to load column definitions. Please try again.
        </div>
      )}
      {data && (
        <div className="flex flex-col gap-3 py-2">
          <div>
            <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">Columns</div>
            <div className="grid grid-cols-2 gap-1.5 max-h-64 overflow-y-auto border border-default rounded-md p-2">
              {ordered.map((col) => (
                <label key={col.key} className="flex items-center gap-2 cursor-pointer text-sm">
                  <Checkbox
                    checked={selected.has(col.key)}
                    onChange={() => toggle(col.key)}
                  />
                  <span>{col.label}</span>
                </label>
              ))}
            </div>
            <div className="mt-1 text-xs text-muted">
              {selected.size} of {ordered.length} selected
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1.5">Format</div>
              <div className="flex gap-1.5">
                <Button
                  size="sm"
                  variant={format === 'xlsx' ? 'primary' : 'secondary'}
                  onClick={() => setFormat('xlsx')}
                >
                  Excel
                </Button>
                <Button
                  size="sm"
                  variant={format === 'csv' ? 'primary' : 'secondary'}
                  onClick={() => setFormat('csv')}
                >
                  CSV
                </Button>
              </div>
            </div>
            <div className="flex items-end">
              <label className="flex items-center gap-2 text-sm">
                <Checkbox checked={saveDefaults} onChange={(e) => setSaveDefaults((e.target as HTMLInputElement).checked)} />
                <span>Save as my default selection</span>
              </label>
            </div>
          </div>
        </div>
      )}

      <div className="flex justify-end gap-2 pt-3 border-t border-default">
        <Button variant="secondary" onClick={onClose} disabled={saveMutation.isPending}>
          Cancel
        </Button>
        <Button
          variant="primary"
          onClick={handleDownload}
          disabled={isLoading || isError || selected.size === 0 || saveMutation.isPending}
          loading={saveMutation.isPending}
        >
          Download
        </Button>
      </div>
    </Modal>
  );
}
