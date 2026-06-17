/**
 * ContactSection — the closing call to action.
 *
 * A single, confident invitation to start a part with Ogami. Now includes an
 * inline quote request form so visitors can send RFQs without leaving the page.
 */

import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ArrowRight, Mail, Phone, CheckCircle, Upload, FileText } from 'lucide-react';
import { AxiosError } from 'axios';
import { DatumMark } from '../components/DatumMark';
import { ScrambleText } from '../components/ScrambleText';
import { COMPANY } from '../data';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { landingApi } from '@/api/landing';
import { cn } from '@/lib/cn';
import { useMagnetic } from '../hooks/useMagnetic';

const quoteSchema = z.object({
  full_name: z.string().min(1, 'Full name is required'),
  company: z.string().min(1, 'Company is required'),
  email: z.string().min(1, 'Email is required').email('Invalid email'),
  part_description: z.string().min(1, 'Part description is required'),
  annual_volume: z.string().optional(),
});

type QuoteForm = z.infer<typeof quoteSchema>;

export function ContactSection() {
  const [drawing, setDrawing] = useState<File | null>(null);
  const [submitted, setSubmitted] = useState(false);
  const submitRef = useMagnetic<HTMLButtonElement>({ strength: 0.22, duration: 0.55 });

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
    <section id="contact" className="relative bg-landing-canvas px-5 py-20 sm:px-8 sm:py-28">
      <div className="mx-auto max-w-6xl">
        <div className="relative overflow-hidden rounded-2xl border border-landing-border-strong bg-landing-surface px-7 py-16 sm:px-14 sm:py-20">
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
                className="mt-5 font-display text-[clamp(2.25rem,5.5vw,4rem)] font-bold leading-[1.02] tracking-[-0.025em] text-landing-text"
              >
                Have a part in mind? Let&apos;s mold it.
              </h2>
              <p
                data-reveal
                data-reveal-delay="0.1"
                className="mt-5 font-sans text-[15px] leading-relaxed text-landing-text-secondary sm:text-lg"
              >
                Send us your drawing or your challenge. Our engineers will come back
                with tooling, tolerance, and timeline — and a clear path to your first
                certified shipment.
              </p>

              <div
                data-reveal
                data-reveal-delay="0.2"
                className="mt-12 flex flex-col gap-4 border-t border-landing-border pt-8 sm:flex-row sm:gap-10"
              >
                <a
                  href={`mailto:${COMPANY.email}`}
                  className="flex items-center gap-2.5 font-mono text-[12px] text-landing-text-secondary transition-colors hover:text-landing-accent"
                >
                  <Mail size={15} className="text-landing-accent" />
                  {COMPANY.email}
                </a>
                <span className="flex items-center gap-2.5 font-mono text-[12px] text-landing-text-secondary">
                  <Phone size={15} className="text-landing-accent" />
                  {COMPANY.phone}
                </span>
                <span className="font-mono text-[12px] text-landing-subtle-text">
                  {COMPANY.locationLine}
                </span>
              </div>
            </div>

            {/* ── Quote form ───────────────────────────────────────── */}
            <div
              data-reveal
              data-reveal-delay="0.15"
              className="rounded-2xl border border-landing-border bg-landing-canvas p-6 sm:p-8"
            >
              {submitted ? (
                <div className="py-8 text-center">
                  <CheckCircle
                    size={40}
                    className="mx-auto text-success"
                    strokeWidth={1.5}
                  />
                  <h3 className="mt-4 font-display text-xl font-semibold text-landing-text">
                    Request received
                  </h3>
                  <p className="mt-2 text-[13px] text-landing-text-secondary">
                    Thank you. Our engineers will review your part and reply within
                    1–2 business days.
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
                    className={cn(
                      'group flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-landing-border bg-landing-elevated px-4 py-5 transition-colors hover:border-landing-accent/40',
                      drawing && 'border-solid border-landing-accent/40',
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
                      <>
                        <FileText size={20} className="text-landing-accent" />
                        <span className="text-[13px] font-medium text-landing-text">
                          {drawing.name}
                        </span>
                        <span className="text-[11px] text-landing-muted">
                          Click to replace
                        </span>
                      </>
                    ) : (
                      <>
                        <Upload size={20} className="text-landing-muted transition-colors group-hover:text-landing-accent" />
                        <span className="text-[13px] font-medium text-landing-text">
                          Attach drawing or spec
                        </span>
                        <span className="text-[11px] text-landing-muted">
                          PDF, STEP, IGES, DWG, DXF, or image
                        </span>
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
                    Request a quote
                    <ArrowRight size={16} />
                  </Button>
                  <p className="text-center text-[11px] text-landing-muted">
                    Prefer email?{' '}
                    <a
                      href={`mailto:${COMPANY.email}?subject=Quote%20request`}
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
