import { useQuery } from '@tanstack/react-query';
import { crmCustomersApi } from '@/api/crm/customers';
import { AgreementDocumentManager } from '@/components/contracts/AgreementDocumentManager';
import { customerAgreementPreviewValues } from '@/components/contracts/customerAgreementPreview';
import { Button } from '@/components/ui/Button';
import type { Customer } from '@/types/accounting';

interface CustomerAgreementSetupStepProps {
  customer: Customer;
  pendingAgreementFiles?: File[];
  onFinish: () => void;
}

export function CustomerAgreementSetupStep({ customer, pendingAgreementFiles = [], onFinish }: CustomerAgreementSetupStepProps) {
  const productAccess = useQuery({
    queryKey: ['crm', 'customers', customer.id, 'product-access'],
    queryFn: () => crmCustomersApi.productAccess(customer.id),
  });
  const warrantyOptions = useQuery({
    queryKey: ['crm', 'customers', customer.id, 'warranty-options'],
    queryFn: () => crmCustomersApi.warrantyOptions(customer.id),
  });

  return (
    <div className="space-y-5 px-4 py-5 sm:px-5">
      <section aria-labelledby="customer-agreement-setup-heading" className="space-y-2">
        <h2 id="customer-agreement-setup-heading" className="text-section-title text-primary">Customer agreement documents</h2>
        <p className="max-w-3xl text-sm text-secondary">
          {customer.name} is saved. Product access and warranty choices are already set; attach or prepare the agreement below.
        </p>
        <p role="note" className="max-w-3xl text-xs text-muted">
          Agreement files remain drafts until reviewed and issued.
        </p>
      </section>

      <AgreementDocumentManager
        realm="customer"
        entityId={customer.id}
        entityName={customer.name}
        allowTemplateCreation
        pendingFiles={pendingAgreementFiles}
        previewValues={customerAgreementPreviewValues(customer, productAccess.data, warrantyOptions.data)}
      />

      <div className="flex flex-wrap justify-end gap-2 border-t border-default pt-4">
        <Button type="button" variant="primary" onClick={onFinish}>Finish and open customer record</Button>
      </div>
    </div>
  );
}
