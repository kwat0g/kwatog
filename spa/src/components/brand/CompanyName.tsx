import { useQuery } from '@tanstack/react-query';
import { landingApi } from '@/api/landing';

/** Live tenant name used by authenticated and external portal surfaces. */
export function CompanyName() {
 const { data } = useQuery({
 queryKey: ['landing', 'contact'],
 queryFn: landingApi.contact,
 staleTime: 300_000,
 });

 return <>{data?.legal_name ?? '—'}</>;
}
