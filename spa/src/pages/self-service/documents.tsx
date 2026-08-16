/**
 * Task SS3 — Self-service document downloads.
 *
 * Employees grab their own documents with no HR involvement:
 * • Auto-generated certificates (employment, gov contributions, BIR 2316)
 * • Payslips (links to the payslip history page)
 *
 * Desktop layout: one dense table — document, coverage year, availability,
 * download — instead of a stack of tap rows. The employment certificate
 * exposes the backend's `with_salary` variant, which the old row-per-document
 * list had no room for.
 *
 * Protected PDFs use the shared authenticated downloader so expired sessions
 * return users to login instead of opening a raw API error in a new tab.
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { LuDownload, LuReceipt, LuChevronRight } from '@/lib/icons';
import { downloadAuthenticatedFile } from '@/api/download';
import { selfServiceApi } from '@/api/self-service';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import type { SelfServiceCertificate, SelfServiceDocumentsResponse } from '@/types/self-service';

/** Coverage year shown per certificate — employment certs are point-in-time. */
function coverageYear(
 cert: SelfServiceCertificate,
 data: SelfServiceDocumentsResponse,
): string | null {
 switch (cert.key) {
 case 'sss':
 case 'philhealth':
 case 'pagibig':
 return String(data.current_year);
 case 'bir_2316':
 return String(data.bir_2316_year);
 default:
 return null;
 }
}

export default function SelfServiceDocumentsPage() {
 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['self-service', 'documents'],
 queryFn: () => selfServiceApi.documents(),
 });

 const urlFor = (cert: SelfServiceCertificate, withSalary = false): string | null => {
 switch (cert.key) {
 case 'employment':
 return selfServiceApi.employmentCertificateUrl(withSalary);
 case 'sss':
 case 'philhealth':
 case 'pagibig':
 return selfServiceApi.contributionCertificateUrl(cert.key, data?.current_year);
 case 'bir_2316':
 return selfServiceApi.bir2316Url(data?.bir_2316_year);
 default:
 return null;
 }
 };

 const download = (url: string, label: string) =>
 void downloadAuthenticatedFile(url, {
 openInNewTab: true,
 errorMessage: `Failed to generate ${label}.`,
 });

 const availableCount = (data?.certificates ?? []).filter((c) => c.available).length;

 return (
 <div>
 <PageHeader
 title="My Documents"
 subtitle={
 data
 ? `${availableCount} of ${data.certificates.length} certificates available to download`
 : 'Download your certificates and payslips — no HR request needed'
 }
 backTo="/self-service"
 backLabel="Self-service"
 />

 <div className="px-5 py-4 space-y-4">
 {/* LOADING */}
 {isLoading && !data && (
 <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-4 items-start">
 <SkeletonBlock className="h-64 rounded-md" />
 <SkeletonBlock className="h-40 rounded-md" />
 </div>
 )}

 {/* ERROR */}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Couldn't load documents"
 description="An error occurred while loading your documents. Please try again."
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {data && (
 <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-4 items-start">
 <Panel
 title="Certificates"
 meta={`${data.certificates.length} documents`}
 noPadding
 >
 <div className="overflow-x-auto">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th className="w-full">Document</Th>
 <Th align="right" className="w-20 whitespace-nowrap">Year</Th>
 <Th className="w-28">Status</Th>
 <Th align="right" className="w-44">Download</Th>
 </tr>
 </thead>
 <tbody>
 {data.certificates.map((cert) => {
 const url = urlFor(cert);
 const year = coverageYear(cert, data);
 const ready = cert.available && url !== null;
 return (
 <tr key={cert.key} className={`${trCls} h-auto align-top`}>
 <Td className="py-2">
 <div className="text-sm text-primary">{cert.label}</div>
 <div className="text-xs text-muted">{cert.note}</div>
 </Td>
 <Td align="right" mono className="py-2 text-muted">
 {year ?? '—'}
 </Td>
 <Td className="py-2">
 <Chip variant={ready ? 'success' : 'neutral'}>
 {ready ? 'Available' : 'Unavailable'}
 </Chip>
 </Td>
 <Td align="right" className="py-2">
 {ready ? (
 <div className="inline-flex items-center gap-1.5">
 {/* Employment cert has a salary-bearing variant
 (backend `with_salary`) used for visa and
 loan applications. */}
 {cert.key === 'employment' && (
 <Button
 variant="secondary"
 size="sm"
 aria-label="Download employment certificate with salary"
 onClick={() => {
 const salaryUrl = urlFor(cert, true);
 if (salaryUrl) download(salaryUrl, `${cert.label} with salary`);
 }}
 >
 With salary
 </Button>
 )}
 <Button
 variant="secondary"
 size="sm"
 icon={<LuDownload size={14} />}
 aria-label={`Download ${cert.label}`}
 onClick={() => download(url, cert.label)}
 >
 PDF
 </Button>
 </div>
 ) : (
 <span className="text-xs text-text-subtle">—</span>
 )}
 </Td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </div>
 </Panel>

 <div className="space-y-4">
 <Panel title="Payslips" noPadding>
 <Link
 to="/self-service/payslips"
 className="flex items-center gap-3 px-4 py-3 hover:bg-subtle transition-colors duration-fast"
 >
 <span className="w-8 h-8 rounded-md bg-subtle flex items-center justify-center text-muted shrink-0">
 <LuReceipt size={16} />
 </span>
 <span className="flex-1 min-w-0">
 <span className="block text-sm font-medium text-primary">View all payslips</span>
 <span className="block text-xs text-muted">Download any period's payslip PDF</span>
 </span>
 <LuChevronRight size={14} className="text-text-subtle shrink-0" aria-hidden="true" />
 </Link>
 </Panel>

 <Panel title="Need something else?">
 <p className="text-xs text-muted">
 Contracts, disciplinary records, and other filed documents are
 released by HR on request — they aren't auto-generated here.
 </p>
 </Panel>
 </div>
 </div>
 )}
 </div>
 </div>
 );
}
