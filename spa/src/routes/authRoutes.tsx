import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { AuthLayout } from '@/layouts/AuthLayout';

const LoginPage = lazy(() => import('@/pages/auth/login'));
const ChangePasswordPage = lazy(() => import('@/pages/auth/change-password'));
const ForgotPasswordPage = lazy(() => import('@/pages/auth/forgot-password'));
const ResetPasswordPage = lazy(() => import('@/pages/auth/reset-password'));

export const authRoutes = (
  <>
    {/* Auth (no AuthGuard) */}
    <Route element={<AuthLayout />}>
      <Route path="/login" element={<LoginPage />} />
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
