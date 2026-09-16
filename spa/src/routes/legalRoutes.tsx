import { lazy } from 'react';
import { Route } from 'react-router-dom';

const PrivacyPolicyPage = lazy(() => import('@/pages/legal/privacy'));
const TermsPage = lazy(() => import('@/pages/legal/terms'));
const CookiePolicyPage = lazy(() => import('@/pages/legal/cookies'));

export const legalRoutes = (
  <>
    <Route path="/privacy" element={<PrivacyPolicyPage />} />
    <Route path="/terms" element={<TermsPage />} />
    <Route path="/cookies" element={<CookiePolicyPage />} />
  </>
);
