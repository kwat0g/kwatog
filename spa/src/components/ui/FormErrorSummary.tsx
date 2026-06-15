import { useMemo } from 'react';

/**
 * Screen-reader-only live region that announces the first validation error
 * inside a form. Works alongside inline field errors for sighted users.
 */
export function FormErrorSummary({ errors }: { errors: Record<string, { message?: string } | undefined> }) {
  const first = useMemo(() => {
    for (const key in errors) {
      const msg = errors[key]?.message;
      if (msg) return msg;
    }
    return null;
  }, [errors]);

  return (
    <div className="sr-only" aria-live="polite" aria-atomic="true">
      {first ?? ''}
    </div>
  );
}
