import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { GuestGuard } from './GuestGuard';
import { useAuthStore } from '@/stores/authStore';
import type { AuthUser } from '@/api/auth';

const authenticatedUser = {
 id: 'u1',
 name: 'Session LuUser',
 email: 'session@ogami.test',
 is_active: true,
 must_change_password: false,
 theme_mode: 'light',
 sidebar_collapsed: false,
 role: { id: 'r1', name: 'Administrator', slug: 'system_admin' },
 employee: null,
 permissions: [],
 features: [],
} satisfies AuthUser;

function renderGuard() {
 return render(
 <MemoryRouter initialEntries={['/login']}>
 <Routes>
 <Route
 path="/login"
 element={
 <GuestGuard>
 <div>Login form</div>
 </GuestGuard>
 }
 />
 <Route path="/dashboard" element={<div>ERP dashboard</div>} />
 <Route path="/change-password" element={<div>Change password</div>} />
 </Routes>
 </MemoryRouter>,
 );
}

describe('GuestGuard', () => {
 beforeEach(() => {
 useAuthStore.setState({
 user: null,
 permissions: new Set(),
 features: new Set(),
 isAuthenticated: false,
 isLoading: false,
 bootstrap: vi.fn(),
 });
 });

 it('shows the login page when no session exists', () => {
 renderGuard();
 expect(screen.getByText('Login form')).toBeInTheDocument();
 });

 it('returns an authenticated user to the ERP instead of showing login', () => {
 useAuthStore.setState({
 user: authenticatedUser,
 isAuthenticated: true,
 isLoading: false,
 });

 renderGuard();
 expect(screen.getByText('ERP dashboard')).toBeInTheDocument();
 expect(screen.queryByText('Login form')).not.toBeInTheDocument();
 });

 it('restores the cookie session before deciding whether to show login', async () => {
 const bootstrap = vi.fn(async () => {
 useAuthStore.setState({
 user: authenticatedUser,
 isAuthenticated: true,
 isLoading: false,
 });
 });
 useAuthStore.setState({ isLoading: true, bootstrap });

 renderGuard();

 await waitFor(() => expect(bootstrap).toHaveBeenCalledTimes(1));
 expect(await screen.findByText('ERP dashboard')).toBeInTheDocument();
 expect(screen.queryByText('Login form')).not.toBeInTheDocument();
 });

 it('routes password-expired sessions to the required password change', () => {
 useAuthStore.setState({
 user: { ...authenticatedUser, must_change_password: true },
 isAuthenticated: true,
 isLoading: false,
 });

 renderGuard();
 expect(screen.getByText('Change password')).toBeInTheDocument();
 });
});
