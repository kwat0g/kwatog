import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Building2 } from 'lucide-react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';

export default function SupplierPortalLoginPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setErrors({});
    setIsSubmitting(true);

    try {
      await supplierPortalApi.login(email, password);
      toast.success('Signed in to Supplier Portal.');
      navigate('/portal/supplier', { replace: true });
    } catch (err) {
      const axe = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = axe.response?.status;
      const data = axe.response?.data;

      if (status === 422 && data?.errors) {
        const mapped: Record<string, string> = {};
        for (const [field, messages] of Object.entries(data.errors)) {
          mapped[field] = messages[0] ?? 'Invalid value.';
        }
        setErrors(mapped);
      } else if (status === 401) {
        toast.error('Invalid email or password.');
      } else if (status === 429) {
        toast.error('Too many attempts. Please wait a moment.');
      } else if (!axe.response) {
        toast.error('Network error. Please check your connection.');
      } else {
        toast.error(data?.message ?? 'Sign-in failed. Please try again.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen w-full flex items-center justify-center bg-canvas px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="flex flex-col items-center mb-6">
          <span className="h-10 w-10 rounded-lg bg-accent text-canvas inline-flex items-center justify-center mb-3">
            <Building2 size={20} />
          </span>
          <h1 className="text-lg font-semibold">Supplier Portal</h1>
          <p className="text-xs text-muted mt-0.5">Sign in to view purchase orders, invoices, and more.</p>
        </div>

        <form onSubmit={onSubmit} className="bg-elevated border border-border rounded-lg p-5 flex flex-col gap-3">
          <Input
            type="email"
            name="email"
            label="Email"
            autoComplete="email"
            autoFocus
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            error={errors.email}
          />
          <Input
            type="password"
            name="password"
            label="Password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={errors.password}
          />

          <Button
            type="submit"
            variant="primary"
            size="md"
            loading={isSubmitting}
            disabled={isSubmitting}
            className="mt-2 w-full"
          >
            {isSubmitting ? 'Signing in…' : 'Sign in'}
          </Button>
        </form>
      </div>
    </div>
  );
}
