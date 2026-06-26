import { lazy } from 'react';
import { Route } from 'react-router-dom';

const CareersPage = lazy(() => import('@/pages/careers'));
const JobPostingDetailPage = lazy(() => import('@/pages/careers/detail'));
const ApplicationTrackPage = lazy(() => import('@/pages/careers/track'));

export const careersRoutes = (
  <>
    <Route path="/careers" element={<CareersPage />} />
    <Route path="/careers/track" element={<ApplicationTrackPage />} />
    <Route path="/careers/:id" element={<JobPostingDetailPage />} />
  </>
);
