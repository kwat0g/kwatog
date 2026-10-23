import type { ReactNode } from 'react';
import { LuExternalLink, LuMapPin, LuPhone } from '@/lib/icons';
import { focusRing, focusRingInset } from '@/lib/focus';
import { googleMapsDirectionsUrl, hasText, telHref } from '@/lib/maps';
import { cn } from '@/lib/cn';

interface Props {
  /** Ship-to address as resolved by the API (snapshot → customer → billing). */
  address: string | null | undefined;
  contactPerson?: string | null;
  contactPhone?: string | null;
  /** Rendered above the address, e.g. the customer name. */
  heading?: ReactNode;
  /** Shown when neither an address nor a contact is on file. */
  emptyHint?: string;
  className?: string;
}

/**
 * Where the goods go — one component for the driver's run sheet and the
 * dispatch desk, so the two surfaces cannot word the destination differently.
 *
 * The address itself is the link. A driver holds the phone in one hand at a
 * gate; asking them to hit a small "Directions" button next to the text is a
 * worse target than the text they are already reading. Google Maps resolves the
 * origin from the device, so tapping it starts turn-by-turn from wherever the
 * truck currently is.
 */
export function ShipToAddress({
  address,
  contactPerson,
  contactPhone,
  heading,
  emptyHint,
  className,
}: Props) {
  const hasAddress = hasText(address);
  const hasPerson = hasText(contactPerson);
  const hasPhone = hasText(contactPhone);

  if (!hasAddress && !hasPerson && !hasPhone) {
    return (
      <div
        className={cn(
          'rounded-md border border-dashed border-default bg-subtle px-3 py-3',
          className,
        )}
      >
        <p className="text-sm text-secondary">
          {emptyHint ?? 'No delivery address on file for this customer.'}
        </p>
        <p className="mt-1 text-xs text-muted">
          Add the delivery address and receiving contact on the customer record so the driver knows
          where to unload.
        </p>
      </div>
    );
  }

  return (
    <div className={cn('space-y-2.5', className)}>
      {heading ? <div className="text-sm font-medium text-primary">{heading}</div> : null}

      {hasAddress ? (
        <a
          href={googleMapsDirectionsUrl(address)}
          target="_blank"
          rel="noreferrer"
          aria-label={`Open driving directions to ${address.trim()} in Google Maps`}
          className={cn(
            'flex min-h-[44px] items-start gap-2 rounded-md border border-default bg-subtle px-3 py-2.5',
            'text-sm text-primary transition-colors hover:border-accent hover:text-accent',
            focusRingInset,
          )}
        >
          <LuMapPin size={16} className="mt-0.5 shrink-0 text-accent" aria-hidden />
          <span className="min-w-0 flex-1">
            <span className="block break-words whitespace-pre-line">{address.trim()}</span>
            <span className="mt-1 inline-flex items-center gap-1 text-xs text-accent">
              Open in Google Maps
              <LuExternalLink size={12} aria-hidden />
            </span>
          </span>
        </a>
      ) : (
        <p className="text-sm text-secondary">
          No delivery address on file — confirm the destination with dispatch before leaving.
        </p>
      )}

      {(hasPerson || hasPhone) && (
        <dl className="space-y-1 text-sm">
          {hasPerson && (
            <div className="flex items-baseline justify-between gap-3">
              <dt className="shrink-0 text-muted">Receiving contact</dt>
              <dd className="min-w-0 text-right break-words text-primary">
                {contactPerson.trim()}
              </dd>
            </div>
          )}
          {hasPhone && (
            <div className="flex items-baseline justify-between gap-3">
              <dt className="shrink-0 text-muted">Phone</dt>
              <dd className="min-w-0 text-right">
                <a
                  href={telHref(contactPhone)}
                  aria-label={`Call the receiving contact at ${contactPhone.trim()}`}
                  className={cn(
                    'inline-flex min-h-[44px] items-center gap-1.5 font-mono tabular-nums text-accent hover:underline',
                    focusRing,
                  )}
                >
                  <LuPhone size={13} aria-hidden />
                  {contactPhone.trim()}
                </a>
              </dd>
            </div>
          )}
        </dl>
      )}
    </div>
  );
}
