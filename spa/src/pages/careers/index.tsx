import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Briefcase, MapPin, Clock } from 'lucide-react';
import { LandingNav } from '@/pages/landing/components/LandingNav';
import { LandingFooter } from '@/pages/landing/components/LandingFooter';
import { publicRecruitmentApi } from '@/api/public-recruitment';
import type { PublicJobPosting } from '@/types/recruitment';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { landingApi } from '@/api/landing';

function formatSalary(min: string | null, max: string | null) {
 if (!min && !max) return null;
 if (min && max) return `${formatPeso(min)} – ${formatPeso(max)}`;
 return min ? `From ${formatPeso(min)}` : `Up to ${formatPeso(max!)}`;
}

export default function CareersPage() {
 const [page, setPage] = useState(1);
 const [menuOpen, setMenuOpen] = useState(false);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['public-postings', page],
 queryFn: () => publicRecruitmentApi.listPostings({ page }).then((r) => r.data),
 placeholderData: (prev) => prev,
 });
 const { data: contact } = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
 const { data: landingContent } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
 const careersIntro = (landingContent?.section_copy?.hero_description ?? '')
 .replaceAll('{{company}}', contact?.legal_name ?? '—')
 .replaceAll('{{partners}}', landingContent?.oem_partners?.join(', ') ?? '—')
 .replaceAll('{{standard}}', landingContent?.quality_policy?.standard ?? '—')
 .replaceAll('{{address}}', contact?.address ?? '—');

 const postings = data?.data ?? [];
 const lastPage = data?.meta?.last_page ?? 1;

 return (
 <div className="min-h-screen bg-canvas" style={{ fontFamily: "'Bricolage Grotesque Variable', sans-serif" }}>
 <LandingNav open={menuOpen} onOpenChange={setMenuOpen} />

 <main className="mx-auto max-w-6xl px-5 pb-24 pt-32">
 <div className="mb-12 text-center">
 <h1 className="text-2xl font-medium tracking-tight text-primary sm:text-2xl">
 Join Our Team
 </h1>
 <p className="mt-4 text-lg text-secondary">
 {careersIntro || '—'}
 </p>
 <Link
 to="/careers/track"
 className="mt-4 inline-block text-sm text-muted underline underline-offset-4 hover:text-primary"
 >
 Already applied? Track your application
 </Link>
 </div>

 {isLoading && (
 <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
 {[1, 2, 3].map((i) => (
 <div key={i} className="h-48 animate-pulse rounded-md border border-default bg-surface" />
 ))}
 </div>
 )}

 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Could not load job postings"
 description="Something went wrong on our end. Try again in a moment."
 action={
 <Button variant="secondary" onClick={() => refetch()}>
 Try again
 </Button>
 }
 />
 )}

 {!isLoading && !isError && postings.length === 0 && (
 <EmptyState
 icon="briefcase"
 title="No open positions right now"
 description="We're not hiring for any roles at the moment — check back soon."
 />
 )}

 {!isLoading && postings.length > 0 && (
 <>
 <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
 {postings.map((posting: PublicJobPosting) => (
 <Link
 key={posting.id}
 to={`/careers/${posting.id}`}
 className="group rounded-md border border-default p-5 transition-colors hover:border-strong"
 >
 <h2 className="text-lg font-medium text-primary group-hover:underline">
 {posting.title}
 </h2>
 <div className="mt-3 flex flex-col gap-2 text-sm text-secondary">
 <span className="flex items-center gap-1.5">
 <MapPin size={14} />
 {posting.department.name}
 </span>
 <span className="flex items-center gap-1.5">
 <Briefcase size={14} />
 {posting.employment_type_label ?? posting.employment_type}
 </span>
 {posting.salary_range && (
 <span className="flex items-center gap-1.5">
 {formatSalary(posting.salary_range.min, posting.salary_range.max)}
 </span>
 )}
 {posting.closes_at && (
 <span className="flex items-center gap-1.5 text-warning">
 <Clock size={14} />
 Closes {formatDate(posting.closes_at)}
 </span>
 )}
 </div>
 </Link>
 ))}
 </div>

 {lastPage > 1 && (
 <div className="mt-8 flex justify-center gap-2">
 {Array.from({ length: lastPage }, (_, i) => i + 1).map((p) => (
 <Button
 key={p}
 variant={p === page ? 'primary' : 'secondary'}
 size="md"
 iconOnly
 aria-label={`Page ${p}`}
 aria-current={p === page ? 'page' : undefined}
 onClick={() => setPage(p)}
 >
 {p}
 </Button>
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
