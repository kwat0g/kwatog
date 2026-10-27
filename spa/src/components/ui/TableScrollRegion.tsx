import { useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { horizontalScrollEdges } from '@/lib/horizontalScrollEdges';

export function TableScrollRegion({
  label,
  children,
  className,
}: {
  label: string;
  children: ReactNode;
  className?: string;
}) {
  const scroller = useRef<HTMLDivElement>(null);
  const [edges, setEdges] = useState({ left: false, right: false });

  useEffect(() => {
    const element = scroller.current;
    if (!element) return;
    const update = () => {
      const next = horizontalScrollEdges(element);
      setEdges((current) => current.left === next.left && current.right === next.right ? current : next);
    };
    update();
    element.addEventListener('scroll', update, { passive: true });
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update);
    observer?.observe(element);
    const table = element.querySelector('table');
    if (table) observer?.observe(table);
    return () => {
      element.removeEventListener('scroll', update);
      observer?.disconnect();
    };
  }, []);

  return (
    <div
      className={cn('scroll-edge-frame min-w-0', className)}
      data-more-left={edges.left}
      data-more-right={edges.right}
    >
      <div
        ref={scroller}
        data-layout="table-scroller"
        role="region"
        aria-label={label}
        tabIndex={0}
        className="overflow-x-auto rounded-md border border-default"
      >
        {children}
      </div>
    </div>
  );
}
