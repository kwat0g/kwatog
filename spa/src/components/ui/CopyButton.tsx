import { useEffect, useState, type ReactNode } from 'react';
import toast from 'react-hot-toast';
import { LuCheck, LuCopy } from '@/lib/icons';
import { Button } from './Button';

interface CopyButtonProps {
  value: string;
  /** Human-readable value name used in the button label and feedback. */
  label: string;
  children?: ReactNode;
  size?: 'xs' | 'sm' | 'md';
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  iconOnly?: boolean;
  className?: string;
  title?: string;
}

export function CopyButton({
  value,
  label,
  children,
  size = 'sm',
  variant = 'secondary',
  iconOnly = false,
  className,
  title,
}: CopyButtonProps) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return;
    const timeout = window.setTimeout(() => setCopied(false), 1800);
    return () => window.clearTimeout(timeout);
  }, [copied]);

  const copy = async () => {
    if (!navigator.clipboard) {
      toast.error('Clipboard is unavailable in this browser.');
      return;
    }

    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      toast.success(`${label} copied.`);
    } catch {
      toast.error(`Could not copy ${label.toLowerCase()}.`);
    }
  };

  return (
    <Button
      type="button"
      size={size}
      variant={variant}
      iconOnly={iconOnly}
      icon={copied ? <LuCheck size={iconOnly ? 13 : 14} /> : <LuCopy size={iconOnly ? 13 : 14} />}
      className={className}
      title={title ?? (copied ? 'Copied' : `Copy ${label.toLowerCase()}`)}
      aria-label={copied ? 'Copied' : `Copy ${label.toLowerCase()}`}
      onClick={() => void copy()}
    >
      {iconOnly ? null : copied ? 'Copied' : children ?? `Copy ${label}`}
    </Button>
  );
}
