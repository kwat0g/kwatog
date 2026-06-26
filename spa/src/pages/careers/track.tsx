import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { Search, CheckCircle, Clock, ArrowLeft, Calendar, MapPin } from 'lucide-react';
import { LandingNav } from '@/pages/landing/components/LandingNav';
import { LandingFooter } from '@/pages/landing/components/LandingFooter';
import { publicRecruitmentApi } from '@/api/public-recruitment';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import type { TrackingInfo } from '@/types/recruitment';

const STAGE_STEPS = [
  'Application Received',
  'Under Review',
  'Interview Stage',
  'Offer Extended',
  'Hired',
];

function stageIndex(status: string): number {
  const map: Record<string, number> = {
    'Application Received': 0,
    'Under Review': 1,
    'Interview Stage': 2,
    'Interview Scheduled': 2,
    'Offer Extended': 3,
    'Hired': 4,
    'Not Selected': -1,
  };
  return map[status] ?? 0;
}

export default function ApplicationTrackPage() {
  const [code, setCode] = useState('');
  const [info, setInfo] = useState<TrackingInfo | null>(null);
  const [notFound, setNotFound] = useState(false);

  const mutation = useMutation({
    mutationFn: (trackingCode: string) =>
      publicRecruitmentApi.track(trackingCode).then((r) => r.data.data),
    onSuccess: (data) => {
      setInfo(data);
      setNotFound(false);
    },
    onError: () => {
      setInfo(null);
      setNotFound(true);
    },
  });

  const handleTrack = (e: React.FormEvent) => {
    e.preventDefault();
    if (code.trim()) {
      mutation.mutate(code.trim().toUpperCase());
    }
  };

  const currentStep = info ? stageIndex(info.status) : -1;
  const isRejected = info?.status === 'Not Selected';

  return (
    <div className="min-h-screen bg-white" style={{ fontFamily: "'Bricolage Grotesque Variable', sans-serif" }}>
      <LandingNav />

      <main className="mx-auto max-w-2xl px-6 pb-24 pt-32">
        <Link
          to="/careers"
          className="mb-8 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900"
        >
          <ArrowLeft size={14} /> Back to careers
        </Link>

        <h1 className="text-3xl font-bold tracking-tight text-neutral-900">Track Your Application</h1>
        <p className="mt-2 text-neutral-600">
          Enter the tracking code you received after submitting your application.
        </p>

        <form onSubmit={handleTrack} className="mt-6 flex gap-3">
          <Input
            value={code}
            onChange={(e) => setCode(e.target.value)}
            placeholder="RCT-XXXXXX"
            className="font-mono uppercase tracking-widest"
          />
          <Button type="submit" disabled={mutation.isPending}>
            <Search size={16} />
            {mutation.isPending ? 'Searching...' : 'Track'}
          </Button>
        </form>

        {notFound && (
          <p className="mt-4 text-sm text-red-600">
            No application found with that tracking code. Please double-check and try again.
          </p>
        )}

        {info && (
          <div className="mt-8 rounded-md border border-neutral-200 p-6">
            <div className="mb-6">
              <h2 className="text-lg font-semibold text-neutral-900">{info.position}</h2>
              <p className="mt-1 text-sm text-neutral-500">
                Applied on {new Date(info.applied_at).toLocaleDateString()}
              </p>
              <p className="mt-1 font-mono text-xs text-neutral-400">{info.tracking_code}</p>
            </div>

            {isRejected ? (
              <div className="rounded-md bg-neutral-50 p-4 text-center">
                <p className="font-medium text-neutral-700">
                  Thank you for your interest. Unfortunately, we have decided to move forward with other candidates.
                </p>
              </div>
            ) : (
              <div className="space-y-0">
                {STAGE_STEPS.map((step, idx) => {
                  const isActive = idx === currentStep;
                  const isDone = idx < currentStep;
                  return (
                    <div key={step} className="flex items-start gap-3 py-2">
                      <div className="flex flex-col items-center">
                        <div
                          className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold ${
                            isDone
                              ? 'bg-emerald-500 text-white'
                              : isActive
                              ? 'bg-neutral-900 text-white'
                              : 'bg-neutral-200 text-neutral-400'
                          }`}
                        >
                          {isDone ? <CheckCircle size={14} /> : idx + 1}
                        </div>
                        {idx < STAGE_STEPS.length - 1 && (
                          <div className={`h-6 w-0.5 ${isDone ? 'bg-emerald-300' : 'bg-neutral-200'}`} />
                        )}
                      </div>
                      <span
                        className={`text-sm ${
                          isActive ? 'font-semibold text-neutral-900' : isDone ? 'text-neutral-600' : 'text-neutral-400'
                        }`}
                      >
                        {step}
                        {isActive && (
                          <span className="ml-2 inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
                            <Clock size={10} /> Current
                          </span>
                        )}
                      </span>
                    </div>
                  );
                })}
              </div>
            )}

            {info.interview && (
              <div className="mt-6 rounded-md bg-blue-50 p-4">
                <h3 className="text-sm font-semibold text-blue-900">Upcoming Interview</h3>
                <div className="mt-2 space-y-1 text-sm text-blue-800">
                  <p className="flex items-center gap-1.5">
                    <Calendar size={14} />
                    {new Date(info.interview.scheduled_at).toLocaleString()}
                  </p>
                  {info.interview.location && (
                    <p className="flex items-center gap-1.5">
                      <MapPin size={14} />
                      {info.interview.location}
                    </p>
                  )}
                </div>
              </div>
            )}
          </div>
        )}
      </main>

      <LandingFooter />
    </div>
  );
}
