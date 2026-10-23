import { LuDownload } from '@/lib/icons';
import { Button } from '@/components/ui/Button';
import { downloadCsv } from '@/lib/csv';
import {
  widgetExportFilename,
  widgetExportTable,
  type CsvTable,
  type WidgetExportSource,
} from '@/lib/widgetExport';

/**
 * The download affordance itself: one icon, one CSV, one accessible name.
 *
 * Separated from {@link WidgetExportButton} because the two dashboard tile
 * flavours build their tables differently (a layout widget owns a typed
 * payload; a metric panel owns a loose analytics envelope) but must not drift
 * in how they look, sound or download.
 */
export function CsvDownloadButton({
  name,
  table,
  timestamp,
}: {
  name: string;
  table: CsvTable;
  /** Date the file is stamped with — data freshness, not the machine clock. */
  timestamp?: string | null;
}) {
  return (
    <Button
      variant="ghost"
      size="sm"
      iconOnly
      icon={<LuDownload size={14} />}
      aria-label={`Export ${name} as CSV`}
      title="Download CSV"
      onClick={() => downloadCsv(widgetExportFilename(name, timestamp), table.header, table.rows)}
      // 44px touch floor below `sm`, matching the rest of the shell's icon
      // actions; a mouse is assumed above it, where the header is 32px tall.
      className="min-h-[44px] min-w-[44px] sm:min-h-0 sm:min-w-0"
    />
  );
}

/**
 * "Take this tile with me" — one CSV per widget, built from the payload the
 * tile is already drawing (F-017).
 *
 * It lives in the tile's header action slot, beside "Open →", so the two ways
 * out of a widget sit together: go to the full surface, or take the numbers.
 *
 * Renders nothing when there is nothing to export. A disabled button would be a
 * dead control that cannot explain itself; absence is honest, and the tile is
 * already saying "Live data unavailable" in that case.
 */
export function WidgetExportButton(props: WidgetExportSource) {
  const table = widgetExportTable(props);

  if (!table) return null;

  return <CsvDownloadButton name={props.name} table={table} timestamp={props.updatedAt} />;
}
