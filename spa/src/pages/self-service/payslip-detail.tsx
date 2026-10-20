import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { downloadAuthenticatedFile } from '@/api/download';
import { selfServiceApi } from '@/api/self-service';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';

export default function SelfServicePayslipDetailPage() {
 const { id } = useParams<{ id: string }>();
 const [isDownloading, setIsDownloading] = useState(false);

 const download = async () => {
  if (!id || isDownloading) return;
  setIsDownloading(true);
  try {
   await downloadAuthenticatedFile(selfServiceApi.payslipUrl(id), {
    errorMessage: 'Could not open this payslip. Check that you are signed in to the employee account for this record.',
   });
  } finally {
   setIsDownloading(false);
  }
 };

 return (
  <div>
   <PageHeader title="Your payslip" backTo="/self-service/payslips" backLabel="All payslips" />
   <div className="max-w-2xl px-5 py-4">
    <Panel title="Secure payslip access">
     <p className="text-sm text-secondary">
      This link opens one payslip after you confirm. Sign in with the employee account that owns the record.
     </p>
     <div className="mt-5 flex flex-wrap items-center gap-3">
      <Button variant="primary" size="touch" loading={isDownloading} onClick={() => void download()}>
       Download payslip PDF
      </Button>
      <Link to="/self-service/payslips" className="text-sm text-link hover:underline">
       View all payslips
      </Link>
     </div>
    </Panel>
   </div>
  </div>
 );
}
