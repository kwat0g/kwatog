import { useEffect, useState, type DragEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { X, ArrowRight, Upload, FileText, CheckCircle, Trash2 } from 'lucide-react';
import { AxiosError } from 'axios';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { landingApi } from '@/api/landing';
import { cn } from '@/lib/cn';

const quoteSchema = z.object({
  full_name: z.string().min(1, 'Full name is required'),
  company: z.string().min(1, 'Company is required'),
  email: z.string().min(1, 'Email is required').email('Invalid email'),
  part_description: z.string().min(1, 'Part description is required'),
  annual_volume: z.string().optional(),
});

type QuoteForm = z.infer<typeof quoteSchema>;

interface QuoteModalProps {
  open: boolean;
  onClose: () => void;
}

function formatBytes(bytes: number, decimals = 1) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const dm = decimals < 0 ? 0 : decimals;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

export function QuoteModal({ open, onClose }: QuoteModalProps) {
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const quoteLabel = content?.section_copy?.hero_cta?.quote_label ?? 'Request Quote';

  const [drawing, setDrawing] = useState<File | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<QuoteForm>({
    resolver: zodResolver(quoteSchema),
  });

  // Esc key and scroll lock
  useEffect(() => {
    if (!open) return;
    document.body.style.overflow = 'hidden';
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = '';
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [open, onClose]);

  if (!open) return null;

  const handleDragOver = (e: DragEvent<HTMLLabelElement>) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(true);
  };

  const handleDragLeave = (e: DragEvent<HTMLLabelElement>) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(false);
  };

  const handleDrop = (e: DragEvent<HTMLLabelElement>) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(false);
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      setDrawing(e.dataTransfer.files[0]);
    }
  };

  const onSubmit = async (data: QuoteForm) => {
    try {
      await landingApi.requestQuote({ ...data, drawing: drawing ?? undefined });
      setSubmitted(true);
      reset();
      setDrawing(null);
    } catch (err) {
      const axe = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const body = axe.response?.data;
      if (axe.response?.status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof QuoteForm, {
            type: 'server',
            message: msgs[0] ?? 'Invalid value.',
          });
        });
      } else {
        setError('root', {
          type: 'server',
          message: body?.message ?? 'Could not send request. Please try again.',
        });
      }
    }
  };

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="quote-modal-title"
      className="fixed inset-0 z-[100] flex items-center justify-center overflow-y-auto p-4 sm:p-6"
    >
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-canvas/80 backdrop-blur-md transition-opacity animate-fade-in"
        onClick={onClose}
      />

      {/* Modal Container */}
      <div className="relative z-10 w-full max-w-lg rounded-lg border border-strong bg-surface p-6 shadow-2xl animate-slide-up sm:p-8">
        <button
          type="button"
          aria-label="Close modal"
          onClick={onClose}
          className="absolute right-4 top-4 rounded-full border border-default p-2 text-muted transition-colors hover:bg-elevated hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
          <X size={18} />
        </button>

        <div className="mb-6">
          <p className="font-mono text-[11px] uppercase tracking-[0.22em] text-accent">
            Rapid Precision RFQ
          </p>
          <h2 id="quote-modal-title" className="mt-1 font-display text-2xl font-medium tracking-tight text-primary sm:text-3xl">
            {quoteLabel}
          </h2>
          <p className="mt-1 font-sans text-xs text-secondary">
            Attach your CAD drawing or technical spec for immediate DFM feedback & quotation.
          </p>
        </div>

        {submitted ? (
          <div className="py-8 text-center">
            <CheckCircle size={44} className="mx-auto text-success" strokeWidth={1.5} />
            <h3 className="mt-4 font-display text-xl font-medium text-primary">
              Request Received
            </h3>
            <p className="mt-2 text-xs text-secondary">
              Our engineering team will review your specifications and reply within 24 hours.
            </p>
            <Button
              type="button"
              variant="secondary"
              className="mt-6"
              onClick={() => setSubmitted(false)}
            >
              Submit Another Part
            </Button>
          </div>
        ) : (
          <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-3.5" noValidate>
            <FormErrorSummary errors={errors} />
            <div className="grid gap-3 sm:grid-cols-2">
              <Input
                label="Full name"
                autoComplete="name"
                {...register('full_name')}
                error={errors.full_name?.message}
              />
              <Input
                label="Company"
                autoComplete="organization"
                {...register('company')}
                error={errors.company?.message}
              />
            </div>
            <Input
              type="email"
              label="Email"
              autoComplete="email"
              {...register('email')}
              error={errors.email?.message}
            />
            <Textarea
              label="Part description"
              rows={3}
              placeholder="Material grade, tightest tolerance, surface finish..."
              {...register('part_description')}
              error={errors.part_description?.message}
            />
            <Input
              type="number"
              label="Estimated annual volume (optional)"
              min={0}
              {...register('annual_volume')}
              error={errors.annual_volume?.message}
            />

            {/* Enhanced File Dropzone */}
            <label
              htmlFor="modal-drawing-upload"
              onDragOver={handleDragOver}
              onDragLeave={handleDragLeave}
              onDrop={handleDrop}
              className={cn(
                'group relative flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border border-dashed px-4 py-4 transition-all',
                isDragging
                  ? 'border-accent bg-accent/10 ring-2 ring-accent/30'
                  : drawing
                  ? 'border-solid border-accent/50 bg-elevated'
                  : 'border-default bg-elevated hover:border-accent/40',
              )}
            >
              <input
                id="modal-drawing-upload"
                type="file"
                accept=".pdf,.step,.stp,.iges,.igs,.dwg,.dxf,.png,.jpg,.jpeg"
                className="sr-only"
                onChange={(e) => setDrawing(e.target.files?.[0] ?? null)}
              />
              {drawing ? (
                <div className="flex w-full items-center justify-between gap-3">
                  <div className="flex items-center gap-2.5 overflow-hidden">
                    <FileText size={20} className="shrink-0 text-accent" />
                    <div className="min-w-0 text-left">
                      <p className="truncate text-xs font-medium text-primary">
                        {drawing.name}
                      </p>
                      <p className="text-[10px] text-muted">
                        {formatBytes(drawing.size)}
                      </p>
                    </div>
                  </div>
                  <button
                    type="button"
                    aria-label="Remove drawing"
                    onClick={(e) => {
                      e.preventDefault();
                      e.stopPropagation();
                      setDrawing(null);
                    }}
                    className="shrink-0 rounded p-1 text-muted transition-colors hover:bg-canvas hover:text-danger"
                  >
                    <Trash2 size={16} />
                  </button>
                </div>
              ) : (
                <>
                  <Upload size={20} className="text-muted transition-colors group-hover:text-accent" />
                  <div className="text-center">
                    <p className="text-xs font-medium text-primary">
                      Drag CAD file or click to browse
                    </p>
                    <div className="mt-1 flex flex-wrap justify-center gap-1">
                      {['.STEP', '.IGES', '.DWG', '.DXF', '.PDF'].map((ext) => (
                        <span key={ext} className="rounded bg-surface px-1.5 py-0.5 font-mono text-[9px] text-muted border border-default">
                          {ext}
                        </span>
                      ))}
                    </div>
                  </div>
                </>
              )}
            </label>

            <Button
              type="submit"
              variant="primary"
              size="lg"
              loading={isSubmitting}
              disabled={isSubmitting}
              className="mt-2 w-full"
            >
              {quoteLabel}
              <ArrowRight size={16} />
            </Button>
          </form>
        )}
      </div>
    </div>
  );
}
