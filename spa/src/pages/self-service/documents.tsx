/**
 * Task SS3 — Self-service document downloads.
 *
 * Employees grab their own documents with no HR involvement:
 *   • Auto-generated certificates (employment, gov contributions, BIR 2316)
 *   • Payslips (links to the payslip history page)
 *
 * PDFs download via <a href> so the browser carries the session cookie — no
 * blob fetch in JS (matches the payslip download pattern).
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Download, FileText, Receipt, ChevronRight } from 'lucide-react';
import { selfServiceApi } from '@/api/self-service';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import type { SelfServiceCertificate } from '@/types/self-service';

export default function SelfServiceDocumentsPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'documents'],
    queryFn: () => selfServiceApi.documents(),
  });

  const urlFor = (cert: SelfServiceCertificate, year?: number): string | null => {
    switch (cert.key) {
      case 'employment':
        return selfServiceApi.employmentCertificateUrl(false);
      case 'sss':
      case 'philhealth':
      case 'pagibig':
        return selfServiceApi.contributionCertificateUrl(cert.key, year);
      case 'bir_2316':
        return selfServiceApi.bir2316Url(data?.bir_2316_year);
      default:
        return null;
    }
  };

  return (
    <div>
      <PageHeader title="My Documents" backTo="/self-service" backLabel="Dashboard" />
      <div className="px-5 py-4 space-y-4">

      {/* LOADING */}
      {isLoading && !data && (
        <div className="space-y-2">
          {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-14 rounded-md" />)}
        </div>
      )}

      {/* ERROR */}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Couldn't load documents"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && (
        <>
          {/* Certificates */}
          <section className="space-y-2">
            <h2 className="text-2xs uppercase tracking-wider text-muted font-medium">
              Certificates
            </h2>
            <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
              {data.certificates.map((cert) => {
                const url = urlFor(cert, data.current_year);
                return (
                  <li key={cert.key} className="flex items-center gap-3 px-3 py-3">
                    <span className="w-9 h-9 rounded-md bg-subtle flex items-center justify-center text-muted shrink-0">
                      <FileText size={18} />
                    </span>
                    <div className="flex-1 min-w-0">
                      <div className="text-sm font-medium truncate">{cert.label}</div>
                      <div className="text-xs text-muted truncate">{cert.note}</div>
                    </div>
                    {cert.available && url ? (
                      <a
                        href={url}
                        target="_blank"
                        rel="noopener"
                        className="shrink-0 inline-flex items-center gap-1 px-3 h-11 text-sm rounded-md border border-default bg-canvas text-primary hover:bg-elevated"
                        aria-label={`Download ${cert.label}`}
                      >
                        <Download size={14} /> PDF
                      </a>
                    ) : (
                      <span className="shrink-0 text-2xs text-subtle px-2">Unavailable</span>
                    )}
                  </li>
                );
              })}
            </ul>
          </section>

          {/* Payslips */}
          <section className="space-y-2">
            <h2 className="text-2xs uppercase tracking-wider text-muted font-medium">Payslips</h2>
            <Link
              to="/self-service/payslips"
              className="flex items-center gap-3 rounded-md border border-default bg-canvas px-3 py-3 hover:bg-elevated"
            >
              <span className="w-9 h-9 rounded-md bg-subtle flex items-center justify-center text-muted shrink-0">
                <Receipt size={18} />
              </span>
              <span className="flex-1 min-w-0">
                <span className="block text-sm font-medium">View all payslips</span>
                <span className="block text-xs text-muted">Download any period's payslip PDF</span>
              </span>
              <ChevronRight size={16} className="text-subtle shrink-0" />
            </Link>
          </section>

          <p className="text-2xs text-muted">
            Need a contract or other filed document? Contact HR — those are
            released on request.
          </p>
        </>
      )}
      </div>{/* .px-5 py-4 */}
    </div>
  );
}
