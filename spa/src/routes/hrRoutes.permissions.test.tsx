import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { MemoryRouter, Routes } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { hrRoutes } from './hrRoutes';

function renderAttendanceRoute(permissions: string[]) {
  useAuthStore.setState({
    isAuthenticated: true,
    permissions: new Set(permissions),
    features: new Set(['attendance']),
  });
  return render(
    <MemoryRouter initialEntries={['/hr/attendance']}>
      <Routes>{hrRoutes}</Routes>
    </MemoryRouter>,
  );
}

afterEach(() => {
  cleanup();
  useAuthStore.setState({ isAuthenticated: false, permissions: new Set(), features: new Set() });
});

describe('Attendance route permissions', () => {
  it('blocks edit-only users because the Attendance list API requires read access', () => {
    renderAttendanceRoute(['attendance.edit']);
    expect(screen.getByText('Page not found')).toBeInTheDocument();
  });

  it('keeps self-service-only readers out of the back-office Attendance page', () => {
    renderAttendanceRoute(['attendance.view']);
    expect(screen.getByText('Page not found')).toBeInTheDocument();
  });
});
