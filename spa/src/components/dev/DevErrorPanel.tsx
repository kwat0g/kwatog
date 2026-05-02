import { useState } from 'react';
import { AlertTriangle, X, Trash2, Copy, ChevronDown, ChevronRight } from 'lucide-react';
import { useErrorLogStore, type ServerErrorEntry } from '@/stores/errorLogStore';
import { cn } from '@/lib/cn';

/**
 * Floating bottom-right panel that surfaces every 4xx/5xx HTTP response
 * captured by the axios interceptor — including Laravel's debug payload
 * (exception class, file, line, stack trace). Visible only in dev so the
 * user doesn't have to open `storage/logs/laravel.log` to diagnose 500s.
 */

const showDevPanel =
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (import.meta as any).env?.DEV ||
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (import.meta as any).env?.VITE_SHOW_DEV_ERRORS === '1';

export function DevErrorPanel() {
  const entries = useErrorLogStore((s) => s.entries);
  const unread = useErrorLogStore((s) => s.unreadCount);
  const clear = useErrorLogStore((s) => s.clear);
  const markRead = useErrorLogStore((s) => s.markRead);
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState<string | null>(null);

  if (!showDevPanel) return null;

  const toggle = () => {
    if (!open) markRead();
    setOpen((o) => !o);
  };

  return (
    <>
      {/* Pill button */}
      <button
        type="button"
        onClick={toggle}
        className={cn(
          'fixed bottom-4 right-4 z-50 flex items-center gap-2 px-3 py-1.5 rounded-full',
          'border border-default bg-canvas shadow-lg text-xs',
          unread > 0 ? 'text-danger-fg border-danger-bg' : 'text-muted',
          'hover:bg-elevated transition-colors',
        )}
        aria-label="Toggle server error log"
      >
        <AlertTriangle size={12} />
        <span className="font-mono tabular-nums">
          {entries.length}
        </span>
        {unread > 0 && (
          <span className="ml-0.5 inline-flex items-center justify-center min-w-[16px] h-[16px] px-1 rounded-full bg-danger-bg text-danger-fg font-medium font-mono tabular-nums">
            {unread}
          </span>
        )}
      </button>

      {open && (
        <div className="fixed bottom-16 right-4 z-50 w-[min(640px,calc(100vw-32px))] max-h-[70vh] flex flex-col rounded-md border border-default bg-canvas shadow-2xl">
          <header className="flex items-center justify-between px-3 py-2 border-b border-default">
            <div className="flex items-center gap-2">
              <AlertTriangle size={14} className="text-danger-fg" />
              <h3 className="text-sm font-medium">Server errors</h3>
              <span className="text-xs text-muted font-mono tabular-nums">{entries.length}</span>
            </div>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={clear}
                className="p-1.5 text-muted hover:text-primary rounded hover:bg-elevated"
                aria-label="Clear log"
                title="Clear log"
              >
                <Trash2 size={12} />
              </button>
              <button
                type="button"
                onClick={() => setOpen(false)}
                className="p-1.5 text-muted hover:text-primary rounded hover:bg-elevated"
                aria-label="Close panel"
              >
                <X size={12} />
              </button>
            </div>
          </header>

          <div className="overflow-y-auto flex-1">
            {entries.length === 0 ? (
              <div className="p-6 text-center text-xs text-muted">
                No server errors captured yet.
              </div>
            ) : (
              <ul className="divide-y divide-subtle">
                {entries.map((e) => (
                  <ErrorRow
                    key={e.id}
                    entry={e}
                    expanded={expanded === e.id}
                    onToggle={() => setExpanded(expanded === e.id ? null : e.id)}
                  />
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </>
  );
}

function ErrorRow({
  entry, expanded, onToggle,
}: {
  entry: ServerErrorEntry;
  expanded: boolean;
  onToggle: () => void;
}) {
  const time = new Date(entry.timestamp).toLocaleTimeString('en-PH', { hour12: false });
  const statusColor = entry.status && entry.status >= 500
    ? 'text-danger-fg' : entry.status === 403 || entry.status === 401
    ? 'text-warning-fg' : 'text-muted';

  const copyAll = () => {
    const blob = JSON.stringify(entry, null, 2);
    navigator.clipboard?.writeText(blob);
  };

  return (
    <li className="text-xs">
      <button
        type="button"
        onClick={onToggle}
        className="w-full text-left px-3 py-2 hover:bg-elevated flex items-start gap-2"
      >
        {expanded
          ? <ChevronDown size={12} className="mt-0.5 text-muted shrink-0" />
          : <ChevronRight size={12} className="mt-0.5 text-muted shrink-0" />}
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={cn('font-mono tabular-nums', statusColor)}>{entry.status ?? '—'}</span>
            <span className="font-mono text-muted">{entry.method}</span>
            <span className="font-mono truncate">{entry.url}</span>
            <span className="font-mono text-muted ml-auto shrink-0">{time}</span>
          </div>
          <div className="mt-1 truncate">
            {entry.exception && <span className="text-danger-fg font-medium">{entry.exception}: </span>}
            <span className="text-primary">{entry.message}</span>
          </div>
        </div>
      </button>

      {expanded && (
        <div className="px-3 pb-3 pl-7 space-y-2">
          {(entry.file || entry.line) && (
            <div className="font-mono text-muted">
              {entry.file}{entry.line ? `:${entry.line}` : ''}
            </div>
          )}
          {entry.trace && entry.trace.length > 0 && (
            <details className="border border-default rounded-md">
              <summary className="px-2 py-1.5 cursor-pointer text-muted hover:text-primary">
                Stack trace ({entry.trace.length} frames)
              </summary>
              <ol className="divide-y divide-subtle font-mono text-2xs">
                {entry.trace.slice(0, 30).map((f, i) => (
                  <li key={i} className="px-2 py-1">
                    <div className="text-muted">
                      {f.class && <span>{f.class}::</span>}
                      <span>{f.function ?? '(closure)'}</span>
                    </div>
                    {f.file && (
                      <div className="text-text-subtle truncate">
                        {f.file}{f.line ? `:${f.line}` : ''}
                      </div>
                    )}
                  </li>
                ))}
              </ol>
            </details>
          )}
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={copyAll}
              className="inline-flex items-center gap-1 px-2 py-1 rounded border border-default text-muted hover:text-primary hover:bg-elevated"
            >
              <Copy size={10} /> Copy JSON
            </button>
          </div>
        </div>
      )}
    </li>
  );
}
