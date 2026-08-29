/**
 * Salvage value is residual value, so it cannot exceed acquisition cost.
 *
 * Mirrors `StoreAssetRequest::withValidator()` and
 * `UpdateAssetRequest::withValidator()`. Over the bound the backend clamps the
 * depreciable base to zero, so an asset saved that way is accepted and then
 * silently never depreciates.
 *
 * Amounts are compared as centavo integers, never `parseFloat`: both arrive
 * from the API as decimal strings and must stay that way (`decimal(15,2)`
 * exists precisely to avoid binary-float rounding).
 */

const DECIMAL = /^\d+(\.\d{1,2})?$/;

export const SALVAGE_OVER_COST_MESSAGE = 'Salvage value cannot exceed the acquisition cost.';

/** Centavos for a `12345.67`-shaped string, or `null` when not comparable. */
export function centavos(amount: string | undefined | null): bigint | null {
  if (!amount || !DECIMAL.test(amount)) return null;
  const [whole, fraction = ''] = amount.split('.');
  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}

/**
 * True only when both amounts are comparable AND salvage is strictly greater.
 * An unparseable or absent amount yields `false` — the field's own rule, or the
 * server, reports that case; a second error here would only confuse.
 */
export function salvageExceedsCost(
  salvage: string | undefined | null,
  acquisitionCost: string | undefined | null,
): boolean {
  const salvageCentavos = centavos(salvage);
  const costCentavos = centavos(acquisitionCost);
  if (salvageCentavos === null || costCentavos === null) return false;
  return salvageCentavos > costCentavos;
}

/**
 * True when the submitted salvage is the stored one, unchanged.
 *
 * The edit form resubmits the loaded value, and acquisition cost is not
 * editable there, so a pre-existing row that already breaks the bound has to
 * stay saveable or its name and department could never be corrected either.
 * The backend applies the same carve-out.
 */
export function salvageIsUnchanged(
  salvage: string | undefined | null,
  storedSalvage: string | undefined | null,
): boolean {
  const submitted = centavos(salvage);
  const stored = centavos(storedSalvage);
  if (submitted === null || stored === null) return false;
  return submitted === stored;
}
