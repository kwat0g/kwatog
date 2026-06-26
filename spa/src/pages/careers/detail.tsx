import { useState, useRef } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ArrowLeft, Upload, CheckCircle, Briefcase, MapPin } from 'lucide-react';
import { AxiosError } from 'axios';
import { LandingNav } from '@/pages/landing/components/LandingNav';
import { LandingFooter } from '@/pages/landing/components/LandingFooter';
import { publicRecruitmentApi } from '@/api/public-recruitment';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';

const EMPLOYMENT_LABELS: Record<string, string> = {
  regular: 'Regular',
  probationary: 'Probationary',
  contractual: 'Contractual',
  project_based: 'Project-Based',
};

const applySchema = z.object({
  first_name: z.string().min(1, 'First name is required').max(100),
  last_name: z.string().min(1, 'Last name is required').max(100),
  email: z.string().min(1, 'Email is required').email('Invalid email'),
  phone: z.string().min(1, 'Phone is required').max(30),
  cover_letter: z.string().max(5000).optional(),
});

type ApplyForm = z.infer<typeof applySchema>;

export default function JobPostingDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [resume, setResume] = useState<File | null>(null);
  const [trackingCode, setTrackingCode] = useState<string | null>(null);
  const [resumeError, setResumeError] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['public-posting', id],
    queryFn: () => publicRecruitmentApi.showPosting(id!).then((r) => r.data.data),
    enabled: !!id,
  });

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ApplyForm>({
    resolver: zodResolver(applySchema),
  });

  const mutation = useMutation({
    mutationFn: (formData: FormData) => publicRecruitmentApi.apply(id!, formData),
    onSuccess: (res) => {
      setTrackingCode(res.data.tracking_code);
    },
    onError: (err: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      const body = err.response?.data;
      if (err.response?.status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof ApplyForm, { message: msgs[0] });
        });
      }
    },
  });

  const onSubmit = (data: ApplyForm) => {
    if (!resume) {
      setResumeError('Please upload your resume (PDF, DOC, or DOCX).');
      return;
    }
    setResumeError(null);
    const fd = new FormData();
    fd.append('first_name', data.first_name);
    fd.append('last_name', data.last_name);
    fd.append('email', data.email);
    fd.append('phone', data.phone);
    if (data.cover_letter) fd.append('cover_letter', data.cover_letter);
    fd.append('resume', resume);
    mutation.mutate(fd);
  };

  const posting = data;

  return (
    <div className="min-h-screen bg-white" style={{ fontFamily: "'Bricolage Grotesque Variable', sans-serif" }}>
      <LandingNav />

      <main className="mx-auto max-w-3xl px-6 pb-24 pt-32">
        <Link
          to="/careers"
          className="mb-8 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900"
        >
          <ArrowLeft size={14} /> Back to all positions
        </Link>

        {isLoading && (
          <div className="space-y-4">
            <div className="h-8 w-2/3 animate-pulse rounded bg-neutral-100" />
            <div className="h-4 w-1/3 animate-pulse rounded bg-neutral-100" />
            <div className="h-32 animate-pulse rounded bg-neutral-50" />
          </div>
        )}

        {isError && <p className="text-neutral-500">Failed to load job posting.</p>}

        {posting && (
          <>
            <h1 className="text-3xl font-bold tracking-tight text-neutral-900">{posting.title}</h1>
            <div className="mt-3 flex flex-wrap gap-4 text-sm text-neutral-600">
              <span className="flex items-center gap-1.5">
                <MapPin size={14} /> {posting.department.name}
              </span>
              <span className="flex items-center gap-1.5">
                <Briefcase size={14} /> {EMPLOYMENT_LABELS[posting.employment_type] ?? posting.employment_type}
              </span>
              {posting.salary_range && (
                <span className="font-mono text-xs tabular-nums">
                  ₱{Number(posting.salary_range.min).toLocaleString()} – ₱{Number(posting.salary_range.max).toLocaleString()}
                </span>
              )}
            </div>

            <section className="mt-8">
              <h2 className="text-lg font-semibold text-neutral-900">Description</h2>
              <p className="mt-2 whitespace-pre-line text-neutral-700">{posting.description}</p>
            </section>

            <section className="mt-6">
              <h2 className="text-lg font-semibold text-neutral-900">Requirements</h2>
              <p className="mt-2 whitespace-pre-line text-neutral-700">{posting.requirements}</p>
            </section>

            {posting.closes_at && (
              <p className="mt-6 text-sm text-amber-600">
                Application deadline: {new Date(posting.closes_at).toLocaleDateString()}
              </p>
            )}

            <hr className="my-10 border-neutral-200" />

            {trackingCode ? (
              <div className="rounded-md border border-emerald-200 bg-emerald-50 p-8 text-center">
                <CheckCircle className="mx-auto mb-3 text-emerald-600" size={40} />
                <h2 className="text-xl font-bold text-neutral-900">Application Submitted!</h2>
                <p className="mt-2 text-neutral-600">
                  Your tracking code is:
                </p>
                <p className="mt-1 font-mono text-2xl font-bold tracking-widest text-neutral-900">
                  {trackingCode}
                </p>
                <p className="mt-3 text-sm text-neutral-500">
                  Save this code. You can check your application status at{' '}
                  <Link to="/careers/track" className="underline">the tracking page</Link>.
                </p>
              </div>
            ) : (
              <div>
                <h2 className="text-xl font-bold text-neutral-900">Apply for this Position</h2>
                <form onSubmit={handleSubmit(onSubmit)} className="mt-6 space-y-4">
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label className="mb-1 block text-sm font-medium text-neutral-700">First Name *</label>
                      <Input {...register('first_name')} />
                      {errors.first_name && <p className="mt-1 text-xs text-red-600">{errors.first_name.message}</p>}
                    </div>
                    <div>
                      <label className="mb-1 block text-sm font-medium text-neutral-700">Last Name *</label>
                      <Input {...register('last_name')} />
                      {errors.last_name && <p className="mt-1 text-xs text-red-600">{errors.last_name.message}</p>}
                    </div>
                  </div>

                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label className="mb-1 block text-sm font-medium text-neutral-700">Email *</label>
                      <Input type="email" {...register('email')} />
                      {errors.email && <p className="mt-1 text-xs text-red-600">{errors.email.message}</p>}
                    </div>
                    <div>
                      <label className="mb-1 block text-sm font-medium text-neutral-700">Phone *</label>
                      <Input {...register('phone')} placeholder="09XX-XXX-XXXX" />
                      {errors.phone && <p className="mt-1 text-xs text-red-600">{errors.phone.message}</p>}
                    </div>
                  </div>

                  <div>
                    <label className="mb-1 block text-sm font-medium text-neutral-700">Resume *</label>
                    <div
                      onClick={() => fileRef.current?.click()}
                      className="flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-neutral-300 px-4 py-3 text-sm text-neutral-600 hover:border-neutral-400"
                    >
                      <Upload size={16} />
                      {resume ? resume.name : 'Click to upload (PDF, DOC, DOCX — max 5MB)'}
                    </div>
                    <input
                      ref={fileRef}
                      type="file"
                      accept=".pdf,.doc,.docx"
                      className="hidden"
                      onChange={(e) => {
                        setResume(e.target.files?.[0] ?? null);
                        setResumeError(null);
                      }}
                    />
                    {resumeError && <p className="mt-1 text-xs text-red-600">{resumeError}</p>}
                  </div>

                  <div>
                    <label className="mb-1 block text-sm font-medium text-neutral-700">Cover Letter</label>
                    <Textarea {...register('cover_letter')} rows={4} placeholder="Tell us why you're a great fit..." />
                  </div>

                  <Button type="submit" disabled={mutation.isPending} className="w-full">
                    {mutation.isPending ? 'Submitting...' : 'Submit Application'}
                  </Button>

                  {mutation.isError && (
                    <p className="text-sm text-red-600">Something went wrong. Please try again.</p>
                  )}
                </form>
              </div>
            )}
          </>
        )}
      </main>

      <LandingFooter />
    </div>
  );
}
