/**
 * ContactSection — the closing call to action.
 *
 * A general enquiry form: name, company, email, phone, message. Deliberately NOT
 * an RFQ intake. Ogami molds to a customer's existing tooling and does not accept
 * custom mold part design, so the old form — part description, annual volume, CAD
 * drawing upload — invited work the company cannot take, from people who then
 * heard nothing back.
 *
 * Submissions land in `contact_inquiries` and surface at /crm/inquiries, where a
 * genuine sales enquiry can be promoted to a CRM lead. A contact form also
 * catches job seekers and supplier pitches, which is exactly why it does not
 * write straight into `leads`.
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ArrowRight, Mail, Phone, CheckCircle } from 'lucide-react';
import { AxiosError } from 'axios';
import { DatumMark } from '@/components/brand/DatumMark';
import { ScrambleText } from '../components/ScrambleText';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { landingApi } from '@/api/landing';
import { useMagnetic } from '../hooks/useMagnetic';

/**
 * Mirrors StoreContactInquiryRequest on the API. `company` and `phone` are
 * optional because a job applicant or a student asking about the plant has
 * neither, and rejecting them would be the same mistake in a smaller form.
 */
const inquirySchema = z.object({
  full_name: z.string().min(1, 'Full name is required'),
  company: z.string().optional(),
  email: z.string().min(1, 'Email is required').email('Invalid email'),
  phone: z.string().optional(),
  message: z
    .string()
    .min(1, 'Message is required')
    .max(2000, 'Message is too long (2000 characters max)'),
});

type InquiryForm = z.infer<typeof inquirySchema>;

export function ContactSection() {
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const { data: content } = useQuery({
    queryKey: ['landing', 'content'],
    queryFn: landingApi.content,
    staleTime: 300_000,
  });
  const salesEmail = contact?.sales_email ?? '';
  const phone = contact?.phone ?? '';
  const address = contact?.address ?? '';
  const sectionCopy = content?.section_copy;
  const ctaLabel = sectionCopy?.hero_cta?.quote_label ?? '—';
  const contactTitle = sectionCopy?.contact_title ?? '—';
  const contactIntro = sectionCopy?.contact_intro ?? '—';
  const [submitted, setSubmitted] = useState(false);
  const submitRef = useMagnetic<HTMLButtonElement>({ strength: 0.22, duration: 0.55 });

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<InquiryForm>({
    resolver: zodResolver(inquirySchema),
  });

  const onSubmit = async (data: InquiryForm) => {
    try {
      await landingApi.submitInquiry(data);
      setSubmitted(true);
      reset();
    } catch (err) {
      const axe = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const body = axe.response?.data;
      if (axe.response?.status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof InquiryForm, {
            type: 'server',
            message: msgs[0] ?? 'Invalid value.',
          });
        });
      } else if (axe.response?.status === 429) {
        setError('root', {
          type: 'server',
          message: 'Too many messages sent. Please try again in a few minutes.',
        });
      } else {
        setError('root', {
          type: 'server',
          message: body?.message ?? 'Could not send message. Please try again.',
        });
      }
    }
  };

  return (
    <section id="contact" className="relative bg-canvas px-5 py-20 sm:px-5 sm:py-28">
      <div className="mx-auto max-w-screen-xl">
        <div className="relative overflow-hidden rounded-lg border border-strong bg-surface px-8 py-20 sm:px-16 sm:py-24">
          {/* atmosphere — blueprint grid */}
          <div
            aria-hidden="true"
            className="absolute inset-0 opacity-70"
            style={{
              backgroundImage:
                'linear-gradient(var(--blueprint-grid) 1px, transparent 1px),' +
                'linear-gradient(90deg, var(--blueprint-grid) 1px, transparent 1px)',
              backgroundSize: 'var(--blueprint-grid-size) var(--blueprint-grid-size)',
              maskImage: 'radial-gradient(120% 100% at 90% 10%, #000 30%, transparent 80%)',
              WebkitMaskImage: 'radial-gradient(120% 100% at 90% 10%, #000 30%, transparent 80%)',
            }}
          />
          <DatumMark
            size={300}
            solidCore={false}
            strokeWidth={0.4}
            className="pointer-events-none absolute -bottom-20 -right-16 text-accent/[0.06] motion-safe:animate-[spin_120s_linear_infinite]"
          />

          <div className="relative grid gap-14 lg:grid-cols-[1fr_1.1fr]">
            {/* ── Copy ─────────────────────────────────────────────── */}
            <div>
              <p data-reveal className="font-mono text-xs uppercase tracking-[0.24em] text-accent">
                <ScrambleText
                  text="Get in touch"
                  trigger="view"
                  className="font-mono text-xs uppercase tracking-[0.24em] text-accent"
                />
              </p>
              <h2
                data-reveal
                data-reveal-delay="0.05"
                className="mt-6 font-display text-[clamp(2.5rem,6vw,4.5rem)] leading-[0.98] tracking-[-0.03em] text-primary"
              >
                {contactTitle}
              </h2>
              <p
                data-reveal
                data-reveal-delay="0.1"
                className="mt-6 font-sans text-base font-light tracking-wide leading-relaxed text-secondary sm:text-xl"
              >
                {contactIntro}
              </p>

              <div
                data-reveal
                data-reveal-delay="0.2"
                className="mt-12 flex flex-col gap-4 border-t border-default pt-8 sm:flex-row sm:gap-10"
              >
                <a
                  href={salesEmail ? `mailto:${salesEmail}` : undefined}
                  className="flex items-center gap-2.5 font-mono text-sm text-secondary transition-colors hover:text-accent"
                >
                  <Mail size={15} className="text-accent" />
                  {salesEmail || '—'}
                </a>
                <span className="flex items-center gap-2.5 font-mono text-sm text-secondary">
                  <Phone size={15} className="text-accent" />
                  {phone || '—'}
                </span>
                <span className="font-mono text-sm text-text-subtle">{address}</span>
              </div>
            </div>

            {/* ── Enquiry form ─────────────────────────────────────── */}
            <div
              data-reveal
              data-reveal-delay="0.15"
              className="rounded-lg border border-default bg-canvas p-6 sm:p-8"
            >
              {submitted ? (
                <div className="py-5 text-center">
                  <CheckCircle size={40} className="mx-auto text-success" strokeWidth={1.5} />
                  <h3 className="mt-4 font-display text-xl text-primary">
                    {sectionCopy?.contact_success_title ?? '—'}
                  </h3>
                  <p className="mt-2 text-base text-secondary">
                    {sectionCopy?.contact_success_body ?? '—'}
                  </p>
                  <Button
                    type="button"
                    variant="secondary"
                    className="mt-5"
                    onClick={() => setSubmitted(false)}
                  >
                    Send another message
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
                      label="Company (optional)"
                      autoComplete="organization"
                      {...register('company')}
                      error={errors.company?.message}
                    />
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <Input
                      type="email"
                      label="Email"
                      autoComplete="email"
                      {...register('email')}
                      error={errors.email?.message}
                    />
                    <Input
                      type="tel"
                      label="Phone (optional)"
                      autoComplete="tel"
                      {...register('phone')}
                      error={errors.phone?.message}
                    />
                  </div>
                  <Textarea
                    label="Message"
                    rows={6}
                    placeholder="How can we help?"
                    {...register('message')}
                    error={errors.message?.message}
                  />

                  <Button
                    ref={submitRef}
                    type="submit"
                    variant="primary"
                    size="lg"
                    loading={isSubmitting}
                    disabled={isSubmitting}
                    className="mt-2 w-full"
                  >
                    {ctaLabel}
                    <ArrowRight size={16} />
                  </Button>
                  <p className="text-center text-xs text-muted">
                    Prefer email?{' '}
                    <a
                      href={contact?.sales_email ? `mailto:${contact.sales_email}` : undefined}
                      className="underline-offset-2 transition-colors hover:text-primary hover:underline"
                    >
                      Write to us directly
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
