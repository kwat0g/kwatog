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
import { formatDate, formatDateTime } from '@/lib/formatDate';

export default function ApplicationTrackPage() {
  const [code, setCode] = useState('');
  const [info, setInfo] = useState<TrackingInfo | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

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

  const currentStep = info
    ? (info.status === 'Not Selected'
      ? -1
      : info.stage_steps.findIndex((step) => step.label === info.status || (info.status === 'Interview Scheduled' && step.value === 'interview')))
    : -1;
  const isRejected = info?.status === 'Not Selected';

  return (
    <div className="min-h-screen bg-canvas" style={{ fontFamily: "'Bricolage Grotesque Variable', sans-serif" }}>
      <LandingNav open={menuOpen} onOpenChange={setMenuOpen} />

      <main className="mx-auto max-w-2xl px-5 pb-24 pt-32">
        <Link
          to="/careers"
          className="mb-8 inline-flex items-center gap-1.5 text-sm text-muted hover:text-primary"
        >
          <ArrowLeft size={14} /> Back to careers
        </Link>

        <h1 className="text-2xl font-medium tracking-tight text-primary">Track Your Application</h1>
        <p className="mt-2 text-secondary">
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
          <p className="mt-4 text-sm text-danger">
            No application found with that tracking code. Please double-check and try again.
          </p>
        )}

        {info && (
          <div className="mt-8 rounded-md border border-default p-5">
            <div className="mb-6">
              <h2 className="text-lg font-medium text-primary">{info.position}</h2>
              <p className="mt-1 text-sm text-muted">
                Applied on {formatDate(info.applied_at)}
              </p>
              <p className="mt-1 font-mono text-xs text-muted">{info.tracking_code}</p>
            </div>

            {isRejected ? (
              <div className="rounded-md bg-surface p-4 text-center">
                <p className="font-medium text-secondary">
                  Thank you for your interest. Unfortunately, we have decided to move forward with other candidates.
                </p>
              </div>
            ) : (
              <div className="space-y-0">
                {info.stage_steps.map((step, idx) => {
                  const isActive = idx === currentStep;
                  const isDone = idx < currentStep;
                  return (
                    <div key={step.value} className="flex items-start gap-3 py-2">
                      <div className="flex flex-col items-center">
                        <div
                          className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium ${
                            isDone
                              ? 'bg-success text-white'
                              : isActive
                              ? 'bg-primary text-canvas'
                              : 'bg-elevated text-muted'
                          }`}
                        >
                          {isDone ? <CheckCircle size={14} /> : idx + 1}
                        </div>
                        {idx < info.stage_steps.length - 1 && (
                          <div className={`h-6 w-0.5 ${isDone ? 'bg-success' : 'bg-elevated'}`} />
                        )}
                      </div>
                      <span
                        className={`text-sm ${
                          isActive ? 'font-medium text-primary' : isDone ? 'text-secondary' : 'text-muted'
                        }`}
                      >
                        {step.label}
                        {isActive && (
                          <span className="ml-2 inline-flex items-center gap-1 rounded-full bg-elevated px-2 py-0.5 text-xs text-secondary">
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
              <div className="mt-6 rounded-md bg-info-bg p-4">
                <h3 className="text-sm font-medium text-info-fg">Upcoming Interview</h3>
                <div className="mt-2 space-y-1 text-sm text-info-fg">
                  <p className="flex items-center gap-1.5">
                    <Calendar size={14} />
                    {formatDateTime(info.interview.scheduled_at)}
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
