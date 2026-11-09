import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { crmCustomersApi } from '@/api/crm/customers';
import { usePermission } from '@/hooks/usePermission';

export function CustomerProductAccessManager({ customerId }: { customerId: string }) {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [search, setSearch] = useState('');
  const [mode, setMode] = useState<'standard' | 'restricted'>('restricted');
  const [productIds, setProductIds] = useState<string[]>([]);
  const query = useQuery({
    queryKey: ['crm', 'customers', customerId, 'product-access'],
    queryFn: () => crmCustomersApi.productAccess(customerId),
  });

  useEffect(() => {
    if (!query.data) return;
    setMode(query.data.portal_catalog_mode);
    setProductIds(query.data.product_ids);
  }, [query.data]);

  const visibleProducts = useMemo(() => {
    const term = search.trim().toLocaleLowerCase();
    return (query.data?.products ?? []).filter((product) => !term
      || product.part_number.toLocaleLowerCase().includes(term)
      || product.name.toLocaleLowerCase().includes(term));
  }, [query.data?.products, search]);

  const save = useMutation({
    mutationFn: () => crmCustomersApi.updateProductAccess(customerId, { portal_catalog_mode: mode, product_ids: productIds }),
    onSuccess: (data) => {
      queryClient.setQueryData(['crm', 'customers', customerId, 'product-access'], data);
      void queryClient.invalidateQueries({ queryKey: ['crm', 'customers', 'detail', customerId] });
      toast.success('Customer catalog access saved.');
    },
    onError: () => toast.error('Could not save customer catalog access.'),
  });

  const toggle = (productId: string, checked: boolean) => setProductIds((current) => checked
    ? [...new Set([...current, productId])]
    : current.filter((id) => id !== productId));

  return (
    <section aria-labelledby="customer-catalog-access-heading" className="space-y-3 border-t border-default pt-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h4 id="customer-catalog-access-heading" className="text-label font-semibold text-primary">Portal product access</h4>
          <p className="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
            Choose the catalog this customer can order from. New customer accounts start restricted until products are assigned.
          </p>
        </div>
        {can('accounting.customers.manage') && <Button type="button" size="sm" disabled={save.isPending || query.isLoading} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save access'}
        </Button>}
      </div>
      {query.isError ? <p role="alert" className="text-sm text-danger-fg">Could not load product access.</p> : query.isLoading ? (
        <p role="status" className="text-sm text-muted">Loading customer catalog…</p>
      ) : (
        <div className="space-y-3">
          <Select label="Catalog access" value={mode} disabled={!can('accounting.customers.manage')} onChange={(event) => setMode(event.target.value as typeof mode)}>
            <option value="restricted">Customer-specific products</option>
            <option value="standard">Standard catalog plus assigned products</option>
          </Select>
          <p className="text-xs leading-relaxed text-muted">
            {mode === 'restricted'
              ? 'Only checked products appear in this customer portal.'
              : 'Standard catalog products are available to this customer. Checked restricted products are added too.'}
          </p>
          <Input aria-label="Search catalog products" placeholder="Search part number or product name" value={search} onChange={(event) => setSearch(event.target.value)} />
          <ul aria-label="Customer portal products" className="max-h-72 divide-y divide-default overflow-y-auto rounded-md border border-default bg-canvas">
            {visibleProducts.map((product) => (
              <li key={product.id} className="flex items-center gap-3 px-3 py-2">
                {product.image_url ? <img src={product.image_url} alt="" className="h-10 w-10 rounded border border-default object-cover" /> : <div aria-hidden="true" className="h-10 w-10 rounded border border-default bg-surface" />}
                <Checkbox
                  className="flex-1"
                  checked={productIds.includes(product.id)}
                  disabled={!can('accounting.customers.manage')}
                  onChange={(event) => toggle(product.id, event.target.checked)}
                  label={<span><span className="font-medium">{product.name}</span><span className="ml-2 font-mono text-xs text-muted">{product.part_number}</span>{product.portal_visibility === 'standard' && <span className="ml-2 text-xs text-muted">Standard</span>}</span>}
                />
              </li>
            ))}
            {visibleProducts.length === 0 && <li className="px-3 py-5 text-center text-sm text-muted">No products match this search.</li>}
          </ul>
          <p className="text-xs text-muted">{productIds.length} restricted product{productIds.length === 1 ? '' : 's'} assigned.</p>
        </div>
      )}
    </section>
  );
}
