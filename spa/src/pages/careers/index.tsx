import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Briefcase, MapPin, Clock } from 'lucide-react';
import { LandingNav } from '@/pages/landing/components/LandingNav';
import { LandingFooter } from '@/pages/landing/components/LandingFooter';
import { publicRecruitmentApi } from '@/api/public-recruitment';
import type { PublicJobPosting } from '@/types/recruitment';

const EMPLOYMENT_LABELS: Record<string, string> = {
  regular: 'Regular',
  probationary: 'Probationary',
  contractual: 'Contractual',
  project_based: 'Project-Based',
};

function formatSalary(min: string | null, max: string | null) {
  if (!min && !max) return null;
  const fmt = (v: string) => `₱${Number(v).toLocaleString()}`;
  if (min && max) return `${fmt(min)} – ${fmt(max)}`;
  return min ? `From ${fmt(min)}` : `Up to ${fmt(max!)}`;
}

export default function CareersPage() {
  const [page, setPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['public-postings', page],
    queryFn: () => publicRecruitmentApi.listPostings({ page }).then((r) => r.data),
  });

  const postings = data?.data ?? [];
  const lastPage = data?.meta?.last_page ?? 1;

  return (
    <div className="min-h-screen bg-white" style={{ fontFamily: "'Bricolage Grotesque Variable', sans-serif" }}>
      <LandingNav />

      <main className="mx-auto max-w-6xl px-6 pb-24 pt-32">
        <div className="mb-12 text-center">
          <h1 className="text-4xl font-bold tracking-tight text-neutral-900 sm:text-5xl">
            Join Our Team
          </h1>
          <p className="mt-4 text-lg text-neutral-600">
            Be part of Philippine Ogami Corporation — a leader in precision plastic injection molding for the automotive industry.
          </p>
          <Link
            to="/careers/track"
            className="mt-4 inline-block text-sm text-neutral-500 underline underline-offset-4 hover:text-neutral-900"
          >
            Already applied? Track your application
          </Link>
        </div>

        {isLoading && (
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {[1, 2, 3].map((i) => (
              <div key={i} className="h-48 animate-pulse rounded-md border border-neutral-200 bg-neutral-50" />
            ))}
          </div>
        )}

        {isError && (
          <p className="text-center text-neutral-500">Failed to load job postings. Please try again later.</p>
        )}

        {!isLoading && !isError && postings.length === 0 && (
          <p className="text-center text-neutral-500">No open positions at the moment. Check back soon.</p>
        )}

        {!isLoading && postings.length > 0 && (
          <>
            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
              {postings.map((posting: PublicJobPosting) => (
                <Link
                  key={posting.id}
                  to={`/careers/${posting.id}`}
                  className="group rounded-md border border-neutral-200 p-6 transition-colors hover:border-neutral-400"
                >
                  <h2 className="text-lg font-semibold text-neutral-900 group-hover:underline">
                    {posting.title}
                  </h2>
                  <div className="mt-3 flex flex-col gap-2 text-sm text-neutral-600">
                    <span className="flex items-center gap-1.5">
                      <MapPin size={14} />
                      {posting.department.name}
                    </span>
                    <span className="flex items-center gap-1.5">
                      <Briefcase size={14} />
                      {EMPLOYMENT_LABELS[posting.employment_type] ?? posting.employment_type}
                    </span>
                    {posting.salary_range && (
                      <span className="flex items-center gap-1.5">
                        <span className="font-mono text-xs tabular-nums">₱</span>
                        {formatSalary(posting.salary_range.min, posting.salary_range.max)}
                      </span>
                    )}
                    {posting.closes_at && (
                      <span className="flex items-center gap-1.5 text-amber-600">
                        <Clock size={14} />
                        Closes {new Date(posting.closes_at).toLocaleDateString()}
                      </span>
                    )}
                  </div>
                </Link>
              ))}
            </div>

            {lastPage > 1 && (
              <div className="mt-8 flex justify-center gap-2">
                {Array.from({ length: lastPage }, (_, i) => i + 1).map((p) => (
                  <button
                    key={p}
                    onClick={() => setPage(p)}
                    className={`h-8 w-8 rounded text-sm ${
                      p === page
                        ? 'bg-neutral-900 text-white'
                        : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'
                    }`}
                  >
                    {p}
                  </button>
                ))}
              </div>
            )}
          </>
        )}
      </main>

      <LandingFooter />
    </div>
  );
}
