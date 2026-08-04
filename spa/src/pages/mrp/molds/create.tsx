import { PageHeader } from '@/components/layout/PageHeader';
import { MoldForm } from './form';

export default function CreateMoldPage() {
 return (
 <div>
 <PageHeader title="New mold" backTo="/mrp/molds" backLabel="Molds"
 breadcrumbs={[
 { label: 'MRP' },
 { label: 'Molds', href: '/mrp/molds' },
 { label: 'New mold' },
 ]} />
 <MoldForm mode="create" />
 </div>
 );
}
