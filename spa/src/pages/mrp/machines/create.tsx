import { PageHeader } from '@/components/layout/PageHeader';
import { MachineForm } from './form';

export default function CreateMachinePage() {
 return (
 <div>
 <PageHeader title="New machine" backTo="/mrp/machines" backLabel="Machines"
 breadcrumbs={[
 { label: 'MRP' },
 { label: 'Machines', href: '/mrp/machines' },
 { label: 'New machine' },
 ]} />
 <MachineForm mode="create" />
 </div>
 );
}
