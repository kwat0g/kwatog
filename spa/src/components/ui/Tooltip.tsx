import { useState, useRef, useEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/cn';

interface TooltipProps {
 content: ReactNode;
 children: ReactNode;
 side?: 'top' | 'right' | 'bottom' | 'left';
 className?: string;
}

export function Tooltip({ content, children, side = 'top', className }: TooltipProps) {
 const [open, setOpen] = useState(false);
 const triggerRef = useRef<HTMLSpanElement>(null);
 const tooltipRef = useRef<HTMLSpanElement>(null);

 useEffect(() => {
  if (!open || !triggerRef.current || !tooltipRef.current) return;
  
  const updatePosition = () => {
   if (!triggerRef.current || !tooltipRef.current) return;
   const trigger = triggerRef.current.getBoundingClientRect();
   const tooltip = tooltipRef.current.getBoundingClientRect();
   
   let top = 0;
   let left = 0;
   
   switch (side) {
    case 'top':
     top = trigger.top - tooltip.height - 6;
     left = trigger.left + (trigger.width / 2) - (tooltip.width / 2);
     break;
    case 'bottom':
     top = trigger.bottom + 6;
     left = trigger.left + (trigger.width / 2) - (tooltip.width / 2);
     break;
    case 'right':
     top = trigger.top + (trigger.height / 2) - (tooltip.height / 2);
     left = trigger.right + 6;
     break;
    case 'left':
     top = trigger.top + (trigger.height / 2) - (tooltip.height / 2);
     left = trigger.left - tooltip.width - 6;
     break;
   }
   
   tooltipRef.current.style.top = `${top}px`;
   tooltipRef.current.style.left = `${left}px`;
  };

  updatePosition();
  
  // Use capture phase for scroll to catch scrolling of any ancestor container
  window.addEventListener('scroll', updatePosition, true);
  window.addEventListener('resize', updatePosition);
  
  return () => {
   window.removeEventListener('scroll', updatePosition, true);
   window.removeEventListener('resize', updatePosition);
  };
 }, [open, side, content]);

 return (
  <span
   ref={triggerRef}
   className="relative inline-flex"
   onMouseEnter={() => setOpen(true)}
   onMouseLeave={() => setOpen(false)}
   onFocus={() => setOpen(true)}
   onBlur={() => setOpen(false)}
  >
   {children}
   {open && content && createPortal(
    <span
     ref={tooltipRef}
     role="tooltip"
     className={cn(
      'fixed z-[100] px-2 py-1 rounded text-xs bg-primary text-canvas whitespace-nowrap pointer-events-none animate-fade-in',
      className,
     )}
     style={{ top: -9999, left: -9999 }}
    >
     {content}
    </span>,
    document.body
   )}
  </span>
 );
}
