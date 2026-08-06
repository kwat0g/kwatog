/**
 * ContactSection — the closing call to action.
 *
 * A single, confident invitation to start a part with Ogami. Now includes an
 * inline quote request form so visitors can send RFQs without leaving the page.
 */

import { useState, type DragEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ArrowRight, Mail, Phone, CheckCircle, Upload, FileText, Trash2 } from 'lucide-react';
import { AxiosError } from 'axios';
import { DatumMark } from '../components/DatumMark';
import { ScrambleText } from '../components/ScrambleText';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { landingApi } from '@/api/landing';
import { cn } from '@/lib/cn';
import { useMagnetic } from '../hooks/useMagnetic';

function formatBytes(bytes: number, decimals = 1) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const dm = decimals < 0 ? 0 : decimals;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

const quoteSchema = z.object({
  full_name: z.string().min(1, 'Full name is required'),
  company: z.string().min(1, 'Company is required'),
  email: z.string().min(1, 'Email is required').email('Invalid email'),
  part_description: z.string().min(1, 'Part description is required'),
  annual_volume: z.string().optional(),
});

type QuoteForm = z.infer<typeof quoteSchema>;

export function ContactSection() {
  const { data: contact } = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const salesEmail = contact?.sales_email || 'sales@ogami.ph';
  const phone = contact?.phone || '+63 (046) 402-1234';
  const address = contact?.address || 'FCIE Dasmariñas, Cavite, Philippines';
  const quoteLabel = content?.section_copy?.hero_cta?.quote_label || 'Request Quote';
  const sectionCopy = content?.section_copy;
  const contactTitle = sectionCopy?.contact_title || 'Start Your Next Precision Part';
  const contactIntro = sectionCopy?.contact_intro || 'Send your 2D/3D CAD drawing or spec sheet for immediate DFM feedback, tooling quotation, and volume production lead times.';
  const [drawing, setDrawing] = useState<File | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const submitRef = useMagnetic<HTMLButtonElement>({ strength: 0.22, duration: 0.55 });

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

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<QuoteForm>({
    resolver: zodResolver(quoteSchema),
  });

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
    <section id="contact" className="relative bg-landing-canvas px-5 py-20 sm:px-5 sm:py-28">
      <div className="mx-auto max-w-screen-xl">
        <div className="relative overflow-hidden rounded-3xl border border-landing-border-strong bg-landing-surface px-8 py-20 sm:px-16 sm:py-24 shadow-2xl">
          {/* atmosphere — soft warm wash + blueprint grid */}
          <div
            aria-hidden="true"
            className="absolute inset-0"
            style={{
              background:
                'radial-gradient(90% 110% at 100% 0%, rgba(28,25,23,0.05) 0%, rgba(250,250,249,0) 60%),' +
                'radial-gradient(90% 100% at 0% 100%, rgba(28,25,23,0.04) 0%, rgba(250,250,249,0) 60%)',
            }}
          />
          <div
            aria-hidden="true"
            className="absolute inset-0 opacity-70"
            style={{
              backgroundImage:
                'linear-gradient(var(--landing-grid) 1px, transparent 1px),' +
                'linear-gradient(90deg, var(--landing-grid) 1px, transparent 1px)',
              backgroundSize: '32px 32px',
              maskImage: 'radial-gradient(120% 100% at 90% 10%, #000 30%, transparent 80%)',
              WebkitMaskImage: 'radial-gradient(120% 100% at 90% 10%, #000 30%, transparent 80%)',
            }}
          />
          <DatumMark
            size={300}
            solidCore={false}
            strokeWidth={0.4}
            className="pointer-events-none absolute -bottom-20 -right-16 text-landing-accent/[0.06] motion-safe:animate-[spin_120s_linear_infinite]"
          />

          <div className="relative grid gap-14 lg:grid-cols-[1fr_1.1fr]">
            {/* ── Copy ─────────────────────────────────────────────── */}
            <div>
              <p
                data-reveal
                className="font-mono text-[11px] uppercase tracking-[0.24em] text-landing-accent"
              >
                <ScrambleText
                  text="Let's build it"
                  trigger="view"
                  className="font-mono text-[11px] uppercase tracking-[0.24em] text-landing-accent"
                />
              </p>
              <h2
                data-reveal
                data-reveal-delay="0.05"
                className="mt-6 font-display text-[clamp(2.5rem,6vw,4.5rem)] font-semibold leading-[0.98] tracking-[-0.03em] text-landing-text"
              >
                {contactTitle}
              </h2>
              <p
                data-reveal
                data-reveal-delay="0.1"
                className="mt-6 font-sans text-base font-light tracking-wide leading-relaxed text-landing-text-secondary sm:text-xl"
              >
                {contactIntro}
              </p>

              <div
                data-reveal
                data-reveal-delay="0.2"
                className="mt-12 flex flex-col gap-4 border-t border-landing-border pt-8 sm:flex-row sm:gap-10"
              >
                <a
                  href={`mailto:${salesEmail}`}
                  className="flex items-center gap-2.5 font-mono text-[12px] text-landing-text-secondary transition-colors hover:text-landing-accent"
                >
                  <Mail size={15} className="text-landing-accent" />
                  {salesEmail}
                </a>
                <span className="flex items-center gap-2.5 font-mono text-[12px] text-landing-text-secondary">
                  <Phone size={15} className="text-landing-accent" />
                  {phone}
                </span>
                <span className="font-mono text-[12px] text-landing-subtle-text">
                  {address}
                </span>
              </div>
            </div>

            {/* ── Quote form ───────────────────────────────────────── */}
            <div
              data-reveal
              data-reveal-delay="0.15"
              className="rounded-2xl border border-landing-border bg-landing-canvas p-6 sm:p-8 shadow-xl shadow-black/5"
            >
              {submitted ? (
                <div className="py-5 text-center">
                  <CheckCircle
                    size={40}
                    className="mx-auto text-success"
                    strokeWidth={1.5}
                  />
                  <h3 className="mt-4 font-display text-xl font-medium text-landing-text">
                    {sectionCopy?.contact_success_title ?? '—'}
                  </h3>
                  <p className="mt-2 text-[13px] text-landing-text-secondary">
                    {sectionCopy?.contact_success_body ?? '—'}
                  </p>
                  <Button
                    type="button"
                    variant="secondary"
                    className="mt-5"
                    onClick={() => setSubmitted(false)}
                  >
                    Send another request
                  </Button>
                </div>
              ) : (
                <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-3" noValidate>
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
                    rows={4}
                    placeholder="Material, tolerance, annual volume, finish requirements..."
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

                  <label
                    htmlFor="drawing-upload"
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                    onDrop={handleDrop}
                    className={cn(
                      'group relative flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border border-dashed px-4 py-4 transition-all',
                      isDragging
                        ? 'border-landing-accent bg-landing-accent/10 ring-2 ring-landing-accent/30'
                        : drawing
                        ? 'border-solid border-landing-accent/50 bg-landing-elevated'
                        : 'border-landing-border bg-landing-elevated hover:border-landing-accent/40',
                    )}
                  >
                    <input
                      id="drawing-upload"
                      type="file"
                      accept=".pdf,.step,.stp,.iges,.igs,.dwg,.dxf,.png,.jpg,.jpeg"
                      className="sr-only"
                      onChange={(e) => setDrawing(e.target.files?.[0] ?? null)}
                    />
                    {drawing ? (
                      <div className="flex w-full items-center justify-between gap-3">
                        <div className="flex items-center gap-2.5 overflow-hidden">
                          <FileText size={20} className="shrink-0 text-landing-accent" />
                          <div className="min-w-0 text-left">
                            <p className="truncate text-xs font-medium text-landing-text">
                              {drawing.name}
                            </p>
                            <p className="text-[10px] text-landing-muted">
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
                          className="shrink-0 rounded p-1 text-landing-muted transition-colors hover:bg-landing-canvas hover:text-danger"
                        >
                          <Trash2 size={16} />
                        </button>
                      </div>
                    ) : (
                      <>
                        <Upload size={20} className="text-landing-muted transition-colors group-hover:text-landing-accent" />
                        <div className="text-center">
                          <p className="text-xs font-medium text-landing-text">
                            Drag CAD drawing or click to browse
                          </p>
                          <div className="mt-1.5 flex flex-wrap justify-center gap-1">
                            {['.STEP', '.IGES', '.DWG', '.DXF', '.PDF'].map((ext) => (
                              <span key={ext} className="rounded bg-landing-surface px-1.5 py-0.5 font-mono text-[9px] text-landing-muted border border-landing-border">
                                {ext}
                              </span>
                            ))}
                          </div>
                        </div>
                      </>
                    )}
                  </label>

                  <Button
                    ref={submitRef}
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
                  <p className="text-center text-[11px] text-landing-muted">
                    Prefer email?{' '}
                    <a
                      href={contact?.sales_email ? `mailto:${contact.sales_email}?subject=Quote%20request` : undefined}
                      className="underline-offset-2 transition-colors hover:text-landing-text hover:underline"
                    >
                      Talk to our team
                    </a>
                  </p>
                </form>
              )}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
