import { lazy, Suspense } from 'react';
import { Route } from 'react-router-dom';
import { SkeletonLandingPage } from '@/components/ui/Skeleton';

// Lazy-loaded so the marketing-only heavy deps (Three.js, GSAP, Lenis, and the
// display font) ship in their own chunk and never weigh down the ERP bundle.
const LandingPage = lazy(() => import('@/pages/landing/LandingPage'));

export const landingRoutes = (
 <Route
 path="/"
 element={
 <Suspense fallback={<SkeletonLandingPage />}>
 <LandingPage />
 </Suspense>
 }
 />
);
