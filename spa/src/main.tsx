import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import App from './App';
import { queryClient } from './lib/queryClient';
import { useThemeStore } from './stores/themeStore';
import { applyPlainMode } from './lib/plainMode';
import { registerSW } from './sw-register';
import '@fontsource-variable/bricolage-grotesque/wght.css';
import './styles/globals.css';

// Initialize theme before first paint (system preference until auth supplies a saved choice).
useThemeStore.getState().init();

// TEMPORARY filming aid: `?plain=1` strips all styling (raw HTML). Safe to remove.
applyPlainMode();

ReactDOM.createRoot(document.getElementById('root')!).render(
 <React.StrictMode>
 <QueryClientProvider client={queryClient}>
 <BrowserRouter>
 <App />
 <Toaster
 position="top-center"
 toastOptions={{
 duration: 4000,
 style: {
 fontSize: '14px',
 borderRadius: '12px',
 padding: '12px 16px',
 boxShadow: 'var(--shadow-menu)',
 background: 'var(--bg-surface)',
 color: 'var(--text-primary)',
 border: '1px solid var(--border-default)',
 backdropFilter: 'blur(12px)',
 WebkitBackdropFilter: 'blur(12px)',
 },
 success: {
 style: {
 background: 'var(--success-bg)',
 color: 'var(--success-fg)',
 border: '1px solid var(--success)',
 backdropFilter: 'blur(12px)',
 },
 iconTheme: { primary: 'var(--success)', secondary: 'var(--success-bg)' },
 },
 error: {
 style: {
 background: 'var(--danger-bg)',
 color: 'var(--danger-fg)',
 border: '1px solid var(--danger)',
 backdropFilter: 'blur(12px)',
 },
 iconTheme: { primary: 'var(--danger)', secondary: 'var(--danger-bg)' },
 duration: 5000,
 },
 }}
 />
 </BrowserRouter>
 </QueryClientProvider>
 </React.StrictMode>,
);

// Register service worker for PWA offline support (factory + driver terminals)
registerSW();
