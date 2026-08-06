import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { Toaster, toast, resolveValue } from 'react-hot-toast';
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
 position="top-right"
 toastOptions={{
 duration: 3000,
 style: {
 fontSize: '13px',
 borderRadius: '6px',
 padding: '12px 16px',
 background: 'var(--bg-elevated)',
 color: 'var(--text-primary)',
 border: '1px solid var(--border-default)',
 },
 success: {
 duration: 3000,
 style: {
 background: 'var(--success-bg)',
 color: 'var(--success-fg)',
 border: '1px solid var(--success)',
 },
 iconTheme: { primary: 'var(--success)', secondary: 'var(--success-bg)' },
 },
 error: {
 duration: 8000,
 style: {
 background: 'var(--danger-bg)',
 color: 'var(--danger-fg)',
 border: '1px solid var(--danger)',
 },
 iconTheme: { primary: 'var(--danger)', secondary: 'var(--danger-bg)' },
 },
 }}
 >
 {(t) => (
 <div
 style={{
 ...t.style,
 opacity: t.visible ? 1 : 0,
 transition: 'opacity 0.2s',
 display: 'flex',
 alignItems: 'center',
 gap: '12px',
 minWidth: '300px'
 }}
 className="relative group"
 role="status"
 aria-live={t.type === 'error' ? 'assertive' : 'polite'}
 >
 <div className="flex-1 flex items-center gap-3 pr-8">
 {t.type === 'error' ? (
 <svg className="w-5 h-5 text-danger shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
 </svg>
 ) : t.type === 'success' ? (
 <svg className="w-5 h-5 text-success shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
 </svg>
 ) : null}
 <div>{resolveValue(t.message, t)}</div>
 </div>
 <button
 type="button"
 onClick={() => toast.dismiss(t.id)}
 className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-muted hover:text-primary rounded-md opacity-0 group-hover:opacity-100 focus:opacity-100 focus:outline-none focus:ring-2 focus:ring-accent transition-all"
 aria-label="Close"
 >
 <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
 </button>
 </div>
 )}
 </Toaster>
 </BrowserRouter>
 </QueryClientProvider>
 </React.StrictMode>,
);

// Register service worker for PWA offline support (factory + driver terminals)
registerSW();
