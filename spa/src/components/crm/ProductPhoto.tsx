import { Modal } from '@/components/ui/Modal';
import { LuEye } from '@/lib/icons';

export interface ProductPhotoDetails {
  url: string;
  name: string;
  partNumber?: string;
}

export function ProductPhotoButton({
  photo,
  className,
  onView,
}: {
  photo: ProductPhotoDetails;
  className: string;
  onView: (photo: ProductPhotoDetails) => void;
}) {
  return (
    <button
      type="button"
      aria-label={`View larger photo of ${photo.name}`}
      title="View larger photo"
      className={`group relative block shrink-0 cursor-zoom-in overflow-hidden rounded border border-default bg-canvas focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent ${className}`}
      onClick={(event) => {
        event.stopPropagation();
        onView(photo);
      }}
    >
      <img src={photo.url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" />
      <span aria-hidden="true" className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded border border-default bg-canvas text-primary opacity-100 transition-opacity md:opacity-0 md:group-hover:opacity-100 md:group-focus-visible:opacity-100">
        <LuEye size={13} />
      </span>
    </button>
  );
}

export function ProductPhotoViewer({
  photo,
  onClose,
}: {
  photo: ProductPhotoDetails | null;
  onClose: () => void;
}) {
  return (
    <Modal isOpen={photo !== null} onClose={onClose} title={photo?.name ?? 'Product photo'} size="xl">
      {photo && (
        <figure className="space-y-3">
          <img
            src={photo.url}
            alt={`${photo.name} product photo`}
            className="mx-auto max-h-[72vh] w-auto max-w-full rounded object-contain"
          />
          {photo.partNumber && (
            <figcaption className="text-center font-mono text-xs text-muted">
              {photo.partNumber}
            </figcaption>
          )}
        </figure>
      )}
    </Modal>
  );
}
