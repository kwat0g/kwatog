import { PageHeader } from '@/components/layout/PageHeader';
import { ProductForm } from './form';

export default function CreateProductPage() {
  return (
    <div>
      <PageHeader title="New product" backTo="/crm/products" backLabel="Products"
        breadcrumbs={[
          { label: 'CRM' },
          { label: 'Products', href: '/crm/products' },
          { label: 'New product' },
        ]} />
      <ProductForm mode="create" />
    </div>
  );
}
