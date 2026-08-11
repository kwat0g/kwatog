import { Component, type ErrorInfo, type ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/Button';

interface Props {
 children: ReactNode;
 fallback?: ReactNode;
}

interface State {
 hasError: boolean;
 error: Error | null;
 /** Short code the user can quote; ties this screen to the console entry. */
 reference: string | null;
}

/**
 * A thrown exception is not a user-facing message.
 *
 * This used to render `error.message` straight into the page, which leaks
 * internals ("Cannot read properties of undefined") to an HR officer who can do
 * nothing with it, and gives support nothing to search on. The message now goes
 * to the console with a reference code, and the screen shows the code.
 */
function makeReference(): string {
 // Not security-sensitive: it only has to be unique enough to grep for in a
 // console log alongside the timestamp.
 return `ERR-${Date.now().toString(36).toUpperCase().slice(-6)}`;
}

export class ErrorBoundary extends Component<Props, State> {
 state: State = { hasError: false, error: null, reference: null };

 static getDerivedStateFromError(error: Error): State {
 return { hasError: true, error, reference: makeReference() };
 }

 componentDidCatch(error: Error, info: ErrorInfo) {
 console.error(`[ErrorBoundary] ${this.state.reference ?? ''}`, error, info.componentStack);
 }

 render() {
 if (this.state.hasError) {
 if (this.props.fallback) return this.props.fallback;
 return (
 <div className="flex flex-col items-center justify-center min-h-[400px] px-5 text-center">
 <AlertTriangle className="w-6 h-6 text-warning-fg mb-3" aria-hidden="true" />
 <h2 className="text-lg font-medium text-primary mb-1">Something went wrong</h2>
 <p className="text-sm text-muted mb-2 max-w-md">
 This screen failed to load. Your work elsewhere is unaffected. If it keeps
 happening, quote the reference below to IT.
 </p>
 {this.state.reference && (
 <p className="text-sm font-mono tabular-nums text-secondary mb-4">
 {this.state.reference}
 </p>
 )}
 <div className="flex items-center gap-2">
 <Button
 variant="secondary"
 onClick={() => this.setState({ hasError: false, error: null, reference: null })}
 >
 Try again
 </Button>
 <Button variant="ghost" onClick={() => window.location.reload()}>
 Reload page
 </Button>
 </div>
 </div>
 );
 }
 return this.props.children;
 }
}
