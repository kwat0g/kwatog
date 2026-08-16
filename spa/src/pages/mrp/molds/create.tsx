import { PageHeader } from '@/components/layout/PageHeader';
import { MoldForm } from './form';

export default function CreateMoldPage() {
 return (
 <div>
 <PageHeader title="New mold" backTo="/mrp/molds" backLabel="Molds"
 />
 <MoldForm mode="create" />
 </div>
 );
}
