/**
 * Navigation links for the delivery surfaces.
 *
 * No API key, no SDK, no map tile provider: Google's universal Maps URL is a
 * plain link, so it opens the installed Maps app on a phone and the web app on
 * a desk. `dir/?api=1&destination=` lets Maps resolve the ORIGIN from the
 * device itself, which is the only origin that is correct on a moving truck —
 * and asking `navigator.geolocation` instead would prompt for a permission the
 * driver can deny, then fail with no route at all.
 */

/** Driving directions from wherever the device is to `destination`. */
export function googleMapsDirectionsUrl(destination: string): string {
  return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination.trim())}`;
}

/**
 * `tel:` link for a hand-entered phone number. Spaces, dashes and parentheses
 * are dropped; a leading `+` and digits survive so international numbers still
 * dial from the device's phone app.
 */
export function telHref(phone: string): string {
  return `tel:${phone.replace(/[^\d+]/g, '')}`;
}

/** True when a field carries something worth rendering. */
export function hasText(value: string | null | undefined): value is string {
  return typeof value === 'string' && value.trim() !== '';
}
