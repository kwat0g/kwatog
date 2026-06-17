import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import App from './App';
import { queryClient } from './lib/queryClient';
import { useThemeStore } from './stores/themeStore';
import { applyPlainMode } from './lib/plainMode';
import './styles/globals.css';

// Initialize theme before first paint (system preference until auth supplies a saved choice).
useThemeStore.getState().init();

// TEMPORARY filming aid: `?plain=1` strips all styling (raw HTML). Safe to remove.
applyPlainMode();

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter
        future={{
          v7_startTransition: true,
          v7_relativeSplatPath: true,
        }}
      >
        <App />
        <Toaster
          position="top-right"
          toastOptions={{
            duration: 4000,
            style: {
              fontSize: '13px',
              borderRadius: '6px',
              padding: '10px 14px',
              boxShadow: '0 4px 12px rgba(0,0,0,0.1)',
            },
            success: {
              style: {
                background: 'var(--success-bg)',
                color: 'var(--success-fg)',
                border: '1px solid var(--success)',
              },
              iconTheme: { primary: 'var(--success)', secondary: 'var(--success-bg)' },
            },
            error: {
              style: {
                background: 'var(--danger-bg)',
                color: 'var(--danger-fg)',
                border: '1px solid var(--danger)',
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
