import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { AuthLayout } from '@/layouts/AuthLayout';

const LoginPage = lazy(() => import('@/pages/auth/login'));
const ChangePasswordPage = lazy(() => import('@/pages/auth/change-password'));

export const authRoutes = (
  <>
    {/* Auth (no AuthGuard) */}
    <Route element={<AuthLayout />}>
      <Route path="/login" element={<LoginPage />} />
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
