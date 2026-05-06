/**
 * WS-E.3 — Generic export button.
 *
 *   <ExportButton resource="hr.employees" filters={{ status: 'active' }} />
 *
 * Hidden automatically when the user lacks the resource's permission
 * (passed via `permission`, falls back to no permission gate). Wires up
 * the same-origin download trigger from `@/api/exports`.
 */
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { buildExportUrl, triggerBrowserDownload, type ExportFormat } from '@/api/exports';
import { Can } from '@/components/guards/Can';

interface ExportButtonProps {
  resource: string;
  filters?: Record<string, string | number | boolean | undefined>;
  format?: ExportFormat;
  /** Permission slug needed to use the export. Falls back to no gate. */
  permission?: string;
  label?: string;
}

export function ExportButton({
  resource,
  filters,
  format = 'csv',
  permission,
  label = 'Export',
}: ExportButtonProps) {
  const onClick = () =>
    triggerBrowserDownload(buildExportUrl({ resource, format, filters }));

  const button = (
    <Button
      variant="secondary"
      size="sm"
      icon={<Download size={12} />}
      onClick={onClick}
    >
      {label}
    </Button>
  );

  return permission ? <Can permission={permission}>{button}</Can> : button;
}
