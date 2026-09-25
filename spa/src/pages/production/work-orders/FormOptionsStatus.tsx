import { Button } from '@/components/ui/Button';

interface FormOptionsStatusProps {
 isLoading: boolean;
 isError: boolean;
 isFetching: boolean;
 onRetry: () => void;
}

export function FormOptionsStatus({ isLoading, isError, isFetching, onRetry }: FormOptionsStatusProps) {
 if (!isLoading && !isError) return null;

 return (
 <div
  role={isError ? 'alert' : 'status'}
  aria-live={isError ? 'assertive' : 'polite'}
  className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md border border-default bg-subtle px-3 py-2 text-xs text-muted"
 >
  <span>{isLoading ? 'Loading work-order choices…' : 'Could not load work-order choices. Your current entries are unchanged.'}</span>
  {isError && (
   <Button type="button" size="sm" loading={isFetching} onClick={onRetry}>
    Retry lookups
   </Button>
  )}
 </div>
 );
}
