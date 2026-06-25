/** Submit Performance Review page — only the assigned reviewer can submit. */
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { performanceReviewsApi } from '@/api/hr/performance-reviews';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import type { ApiValidationError } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';

const RATING_CATEGORIES = [
  { key: 'job_knowledge', label: 'Job Knowledge' },
  { key: 'work_quality', label: 'Work Quality' },
  { key: 'productivity', label: 'Productivity' },
  { key: 'communication', label: 'Communication' },
  { key: 'teamwork', label: 'Teamwork' },
  { key: 'initiative', label: 'Initiative' },
  { key: 'attendance', label: 'Attendance & Punctuality' },
];

const OVERALL_RATINGS = [
  'Outstanding',
  'Exceeds Expectations',
  'Meets Expectations',
  'Needs Improvement',
  'Unsatisfactory',
] as const;

const submitSchema = z.object({
  job_knowledge: z.string().min(1, 'Required'),
  work_quality: z.string().min(1, 'Required'),
  productivity: z.string().min(1, 'Required'),
  communication: z.string().min(1, 'Required'),
  teamwork: z.string().min(1, 'Required'),
  initiative: z.string().min(1, 'Required'),
  attendance: z.string().min(1, 'Required'),
  strengths: z.string().min(1, 'Strengths are required').max(2000),
  improvements: z.string().min(1, 'Areas for improvement are required').max(2000),
  goals: z.string().min(1, 'Goals are required').max(2000),
  overall_score: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Enter a valid score (e.g. 4.5)'),
  overall_rating: z.string().min(1, 'Overall rating is required'),
});
type FormValues = z.infer<typeof submitSchema>;

export default function SubmitReviewPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: review, isLoading, isError } = useQuery({
    queryKey: ['performance-reviews', id],
    queryFn: () => performanceReviewsApi.show(id!),
    enabled: !!id,
  });

  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(submitSchema),
    defaultValues: {
      job_knowledge: '', work_quality: '', productivity: '',
      communication: '', teamwork: '', initiative: '', attendance: '',
      strengths: '', improvements: '', goals: '',
      overall_score: '', overall_rating: '',
    },
  });

  const mutation = useMutation({
    mutationFn: (d: FormValues) => {
      const ratings: Record<string, number> = {};
      RATING_CATEGORIES.forEach((cat) => {
        ratings[cat.key] = parseFloat(d[cat.key as keyof FormValues] as string);
      });
      return performanceReviewsApi.submit(id!, {
        ratings,
        strengths: d.strengths,
        improvements: d.improvements,
        goals: d.goals,
        overall_score: d.overall_score,
        overall_rating: d.overall_rating,
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['performance-reviews'] });
      toast.success('Performance review submitted successfully.');
      navigate('/hr/performance-reviews/reviews');
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422) {
        const data = e.response.data;
        if (data.errors) {
          Object.entries(data.errors).forEach(([f, msgs]) =>
            setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
          );
        } else if (data.message) {
          toast.error(data.message);
        }
      } else if (e.response?.status === 403) {
        toast.error('You are not authorized to submit this review.');
      } else {
        toast.error('Failed to submit review.');
      }
    },
  });

  if (isLoading) return <SkeletonTable columns={4} rows={4} />;
  if (isError || !review) {
    return (
      <EmptyState icon="alert-circle" title="Failed to load review"
        action={<Button variant="secondary" onClick={() => navigate('/hr/performance-reviews/reviews')}>Back</Button>} />
    );
  }

  return (
    <div>
      <PageHeader
        title="Submit Performance Review"
        backTo="/hr/performance-reviews/reviews"
        backLabel="Reviews"
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Performance Reviews', href: '/hr/performance-reviews/reviews' },
          { label: 'Submit Review' },
        ]}
      />
      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-3xl mx-auto px-5 py-6 space-y-5"
      >
        {/* Review context */}
        <Panel title="Review details">
          <div className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <span className="text-muted">Employee:</span>{' '}
              <span className="font-medium">{review.employee.first_name} {review.employee.last_name}</span>
            </div>
            <div>
              <span className="text-muted">Reviewer:</span>{' '}
              <span className="font-medium">{review.reviewer.first_name} {review.reviewer.last_name}</span>
            </div>
            <div>
              <span className="text-muted">Cycle:</span>{' '}
              <span>{review.cycle.name}</span>
            </div>
            <div>
              <span className="text-muted">Status:</span>{' '}
              <span className="capitalize">{review.status.replace('_', ' ')}</span>
            </div>
          </div>
        </Panel>

        {/* Category ratings */}
        <Panel title="Category Ratings">
          <p className="text-sm text-muted mb-3">Rate each category from 1 (lowest) to 5 (highest).</p>
          <div className="grid grid-cols-2 gap-3">
            {RATING_CATEGORIES.map((cat) => (
              <Select
                key={cat.key}
                label={cat.label}
                required
                {...register(cat.key as keyof FormValues)}
                error={errors[cat.key as keyof FormValues]?.message}
              >
                <option value="">-- Select --</option>
                <option value="1">1 - Unsatisfactory</option>
                <option value="2">2 - Needs Improvement</option>
                <option value="3">3 - Meets Expectations</option>
                <option value="4">4 - Exceeds Expectations</option>
                <option value="5">5 - Outstanding</option>
              </Select>
            ))}
          </div>
        </Panel>

        {/* Qualitative feedback */}
        <Panel title="Qualitative Feedback">
          <div className="space-y-3">
            <Textarea
              label="Strengths"
              required
              {...register('strengths')}
              error={errors.strengths?.message}
              rows={3}
              placeholder="Describe the employee's key strengths..."
            />
            <Textarea
              label="Areas for Improvement"
              required
              {...register('improvements')}
              error={errors.improvements?.message}
              rows={3}
              placeholder="Describe areas where the employee can improve..."
            />
            <Textarea
              label="Goals for Next Period"
              required
              {...register('goals')}
              error={errors.goals?.message}
              rows={3}
              placeholder="Set development goals for the next review period..."
            />
          </div>
        </Panel>

        {/* Overall assessment */}
        <Panel title="Overall Assessment">
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Overall Score"
              required
              {...register('overall_score')}
              error={errors.overall_score?.message}
              placeholder="e.g. 4.2"
            />
            <Select
              label="Overall Rating"
              required
              {...register('overall_rating')}
              error={errors.overall_rating?.message}
            >
              <option value="">-- Select --</option>
              {OVERALL_RATINGS.map((r) => (
                <option key={r} value={r}>{r}</option>
              ))}
            </Select>
          </div>
        </Panel>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/hr/performance-reviews/reviews')}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Submitting...' : 'Submit review'}
          </Button>
        </div>
      </form>
    </div>
  );
}
