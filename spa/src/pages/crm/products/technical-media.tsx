import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import type { Product, ProductImage } from '@/types/crm';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { ProductPhotoButton, ProductPhotoViewer, type ProductPhotoDetails } from '@/components/crm/ProductPhoto';
import { Textarea } from '@/components/ui/Textarea';
import { LuTrash2 } from '@/lib/icons';

type RemovalTarget = { kind: 'image'; id: string; label: string } | { kind: 'blueprint'; id: string; label: string };

function apiMessage(error: unknown, fallback: string): string {
  return (error as { response?: { data?: { message?: string } } }).response?.data?.message ?? fallback;
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function ProductTechnicalMedia({
  product,
  canManage,
}: {
  product: Product;
  canManage: boolean;
}) {
  const queryClient = useQueryClient();
  const [photoPreview, setPhotoPreview] = useState<ProductPhotoDetails | null>(null);
  const [blueprintTitle, setBlueprintTitle] = useState('');
  const [blueprintRevision, setBlueprintRevision] = useState('');
  const [blueprintNotes, setBlueprintNotes] = useState('');
  const [blueprintFile, setBlueprintFile] = useState<File | null>(null);
  const [removal, setRemoval] = useState<RemovalTarget | null>(null);
  const images = product.images ?? [];
  const blueprints = product.blueprints ?? [];
  const isVisible = product.portal_visibility === 'standard' || (product.portal_customers?.length ?? 0) > 0;

  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['crm', 'products', 'detail', product.id] }),
      queryClient.invalidateQueries({ queryKey: ['crm', 'products'] }),
    ]);
  };
  const uploadImages = useMutation({
    mutationFn: (files: File[]) => productsApi.uploadImages(product.id, files),
    onSuccess: async () => { toast.success('Product photos added.'); await refresh(); },
    onError: (error) => toast.error(apiMessage(error, 'Could not upload product photos.')),
  });
  const setPrimary = useMutation({
    mutationFn: (imageId: string) => productsApi.setPrimaryImage(product.id, imageId),
    onSuccess: async () => { toast.success('Primary product photo updated.'); await refresh(); },
    onError: (error) => toast.error(apiMessage(error, 'Could not update the primary photo.')),
  });
  const deleteImage = useMutation({
    mutationFn: (imageId: string) => productsApi.deleteImage(product.id, imageId),
    onSuccess: async () => { setRemoval(null); toast.success('Product photo removed.'); await refresh(); },
    onError: (error) => toast.error(apiMessage(error, 'Could not remove the product photo.')),
  });
  const uploadBlueprint = useMutation({
    mutationFn: (file: File) => productsApi.uploadBlueprint(product.id, {
      title: blueprintTitle.trim(), revision: blueprintRevision.trim(), notes: blueprintNotes.trim() || undefined, file,
    }),
    onSuccess: async () => {
      setBlueprintTitle('');
      setBlueprintRevision('');
      setBlueprintNotes('');
      setBlueprintFile(null);
      toast.success('Blueprint revision uploaded.');
      await refresh();
    },
    onError: (error) => toast.error(apiMessage(error, 'Could not upload the blueprint.')),
  });
  const deleteBlueprint = useMutation({
    mutationFn: (blueprintId: string) => productsApi.deleteBlueprint(product.id, blueprintId),
    onSuccess: async () => { setRemoval(null); toast.success('Blueprint removed.'); await refresh(); },
    onError: (error) => toast.error(apiMessage(error, 'Could not remove the blueprint.')),
  });

  const submitBlueprint = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!blueprintFile || !blueprintTitle.trim() || !blueprintRevision.trim() || uploadBlueprint.isPending) return;
    uploadBlueprint.mutate(blueprintFile);
  };
  const confirmRemoval = () => {
    if (!removal) return;
    if (removal.kind === 'image') deleteImage.mutate(removal.id);
    else deleteBlueprint.mutate(removal.id);
  };
  const removePending = deleteImage.isPending || deleteBlueprint.isPending;
  const lastVisibleBlueprint = product.is_customizable && isVisible && blueprints.length <= 1;

  return (
    <>
      <ProductPhotoViewer photo={photoPreview} onClose={() => setPhotoPreview(null)} />
      <ConfirmDialog
        isOpen={removal !== null}
        onClose={() => setRemoval(null)}
        onConfirm={confirmRemoval}
        title={removal?.kind === 'blueprint' ? 'Remove this blueprint?' : 'Remove this product photo?'}
        description={removal ? `${removal.label} will no longer be available on this product.` : undefined}
        confirmLabel="Remove file"
        variant="danger"
        pending={removePending}
        disabled={removal?.kind === 'blueprint' && lastVisibleBlueprint}
      />

      <Panel title="Product photos" meta={`${images.length} of 10`}>
        {images.length === 0 ? (
          <p className="text-sm text-muted">No product photos uploaded yet.</p>
        ) : (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5" aria-label="Product photo gallery">
            {images.map((image: ProductImage, index) => {
              const alt = image.alt_text?.trim() || `${product.name} product photo ${index + 1}`;
              const photo = { url: image.url, name: product.name, partNumber: product.part_number, altText: alt };
              return (
                <li key={image.id} className="min-w-0 rounded-md border border-default bg-surface p-2">
                  <ProductPhotoButton photo={photo} className="aspect-square w-full" onView={setPhotoPreview} />
                  <div className="mt-2 flex min-w-0 items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate text-xs text-secondary" title={alt}>{alt}</p>
                      {image.is_primary && <p className="mt-1 text-xs font-medium text-accent">Primary photo</p>}
                    </div>
                    {canManage && (
                      <Button
                        size="xs"
                        variant="ghost"
                        aria-label={`Remove ${alt}`}
                        icon={<LuTrash2 size={13} />}
                        onClick={() => setRemoval({ kind: 'image', id: image.id, label: alt })}
                      />
                    )}
                  </div>
                  {canManage && !image.is_primary && (
                    <Button
                      size="xs"
                      variant="secondary"
                      className="mt-2 w-full"
                      disabled={setPrimary.isPending}
                      onClick={() => setPrimary.mutate(image.id)}
                    >
                      Set {alt} as primary
                    </Button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
        {canManage && (
          <div className="mt-4 border-t border-default pt-4">
            <label htmlFor="product-gallery-upload" className="mb-1 block text-sm font-medium text-primary">Add product photos</label>
            <input
              id="product-gallery-upload"
              type="file"
              accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
              multiple
              disabled={uploadImages.isPending || images.length >= 10}
              onChange={(event) => {
                const files = Array.from(event.currentTarget.files ?? []);
                if (files.length) uploadImages.mutate(files);
                event.currentTarget.value = '';
              }}
              className="block w-full text-sm text-secondary file:mr-3 file:rounded-md file:border file:border-default file:bg-surface file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            />
            <p className="mt-1 text-xs text-muted">Upload up to 10 JPEG, PNG, or WebP photos. Each file can be up to 5 MB.</p>
          </div>
        )}
      </Panel>

      <Panel title="Product blueprints" meta={product.is_customizable ? 'Customization required' : 'Technical reference'}>
        {product.is_customizable && blueprints.length === 0 && (
          <p role="status" className="mb-3 rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
            Upload an approved drawing before listing this customizable product for customers.
          </p>
        )}
        {blueprints.length === 0 ? (
          <p className="text-sm text-muted">No blueprint revisions uploaded yet.</p>
        ) : (
          <ul className="divide-y divide-subtle border-y border-default" aria-label="Product blueprint revisions">
            {blueprints.map((blueprint) => (
              <li key={blueprint.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div className="min-w-0">
                  <p className="break-words text-sm font-medium text-primary">{blueprint.title}</p>
                  <p className="mt-0.5 text-xs text-muted">Revision {blueprint.revision} · {blueprint.filename} · {formatFileSize(blueprint.size)}</p>
                  {blueprint.notes && <p className="mt-1 whitespace-pre-wrap break-words text-xs text-secondary">{blueprint.notes}</p>}
                </div>
                <div className="flex items-center gap-2">
                  {blueprint.download_url && <a href={blueprint.download_url} className="min-h-10 rounded px-3 py-2 text-sm font-medium text-accent hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">Download</a>}
                  {canManage && (
                    <Button
                      size="xs"
                      variant="ghost"
                      aria-label={`Remove blueprint ${blueprint.title} revision ${blueprint.revision}`}
                      icon={<LuTrash2 size={13} />}
                      disabled={lastVisibleBlueprint}
                      onClick={() => setRemoval({ kind: 'blueprint', id: blueprint.id, label: `${blueprint.title} revision ${blueprint.revision}` })}
                    />
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
        {canManage && (
          <form className="mt-4 grid gap-3 border-t border-default pt-4 sm:grid-cols-2" onSubmit={submitBlueprint}>
            <Input id="product-blueprint-title" label="Blueprint title" required maxLength={160} value={blueprintTitle} onChange={(event) => setBlueprintTitle(event.target.value)} />
            <Input id="product-blueprint-revision" label="Revision" required maxLength={40} value={blueprintRevision} onChange={(event) => setBlueprintRevision(event.target.value)} />
            <div className="sm:col-span-2">
              <Textarea label="Blueprint notes" rows={2} maxLength={2000} value={blueprintNotes} onChange={(event) => setBlueprintNotes(event.target.value)} />
            </div>
            <label className="sm:col-span-2">
              <span className="mb-1 block text-sm font-medium text-primary">Blueprint file</span>
              <input
                type="file"
                aria-label="Blueprint file"
                accept="application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp"
                required
                disabled={uploadBlueprint.isPending}
                onChange={(event) => setBlueprintFile(event.currentTarget.files?.[0] ?? null)}
                className="block w-full text-sm text-secondary file:mr-3 file:rounded-md file:border file:border-default file:bg-surface file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
              />
              <span className="mt-1 block text-xs text-muted">PDF, JPEG, PNG, or WebP up to 20 MB.</span>
            </label>
            <div className="sm:col-span-2 flex justify-end">
              <Button type="submit" variant="primary" disabled={!blueprintFile || !blueprintTitle.trim() || !blueprintRevision.trim() || uploadBlueprint.isPending} loading={uploadBlueprint.isPending}>
                Upload blueprint
              </Button>
            </div>
          </form>
        )}
      </Panel>
    </>
  );
}
