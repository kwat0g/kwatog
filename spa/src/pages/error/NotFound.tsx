import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { useAuthStore } from '@/stores/authStore';
import { useEffect } from 'react';

export function NotFoundState({ fullPage = true }: { fullPage?: boolean }) {
 // Sending an anonymous visitor to /dashboard just bounces them through the
 // auth guard to /login, which reads as a second failure. Send them somewhere
 // that exists for them.
 const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
 const destination = isAuthenticated ? '/dashboard' : '/login';
 const label = isAuthenticated ? 'Return home' : 'Go to sign in';

 useEffect(() => {
 document.title = 'Page not found · ERP';
 }, []);

 return (
 <div className={fullPage
 ? 'min-h-screen flex items-center justify-center bg-canvas'
 : 'min-h-[400px] flex items-center justify-center bg-canvas'}>
 <EmptyState
 icon="file-question"
 title="Page not found"
 description="The page you're looking for doesn't exist or has been moved."
 action={
 <Link to={destination}>
 <Button variant="primary">{label}</Button>
 </Link>
 }
 />
 </div>
 );
}

export default function NotFoundPage() {
 return <NotFoundState />;
}
