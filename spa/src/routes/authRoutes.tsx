import { lazy, Suspense } from 'react';
import { Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { GuestGuard } from '@/components/guards/GuestGuard';
import { AuthLayout } from '@/layouts/AuthLayout';
import { SkeletonForm } from '@/components/ui/Skeleton';

const LoginPage = lazy(() => import('@/pages/auth/login'));
const ChangePasswordPage = lazy(() => import('@/pages/auth/change-password'));
const ForgotPasswordPage = lazy(() => import('@/pages/auth/forgot-password'));
const ResetPasswordPage = lazy(() => import('@/pages/auth/reset-password'));

export const authRoutes = (
 <>
 {/* Restore an existing cookie session before showing the login form. */}
 <Route
 element={
 <GuestGuard>
 <AuthLayout />
 </GuestGuard>
 }
 >
 <Route
 path="/login"
 element={
 <Suspense fallback={<SkeletonForm />}>
 <LoginPage />
 </Suspense>
 }
 />
 </Route>

 {/* Password recovery remains available without a session. */}
 <Route element={<AuthLayout />}>
 <Route path="/forgot-password" element={<ForgotPasswordPage />} />
 <Route path="/reset-password" element={<ResetPasswordPage />} />
 </Route>

 <Route
 path="/change-password"
 element={
 <AuthGuard>
 <AuthLayout />
 </AuthGuard>
 }
 >
 <Route index element={<ChangePasswordPage />} />
 </Route>
 </>
);
