/**
 * Preview totals for an RFQ quotation form (supplier portal and the buyer's
 * manual entry). Integer centavos throughout; the server recalculates on save
 * and its figures are the record.
 */
export type QuoteVatTreatment = 'exclusive' | 'inclusive' | 'none';

/** quantity × price in whole centavos, exact via BigInt, rounded half up. */
export function lineCentavos(quantity: string, price: string): number {
  const parse = (v: string) => {
    const m = /^(\d+)(?:\.(\d*))?$/.exec(v.trim());
    return m ? { digits: BigInt(`${m[1]}${m[2] ?? ''}`), scale: (m[2] ?? '').length } : null;
  };
  const q = parse(quantity);
  const p = parse(price);
  if (!q || !p) return 0;
  const scale = q.scale + p.scale;
  const product = q.digits * p.digits;
  if (scale <= 2) return Number(product * 10n ** BigInt(2 - scale));
  const div = 10n ** BigInt(scale - 2);
  return Number((product * 2n + div) / (div * 2n));
}

/**
 * Goods, VAT and total in centavos. VAT is on goods + freight; inclusive
 * prices already contain it. `vatRate` null (policy not loaded) shows no VAT.
 */
export function quoteTotals(
  goodsCentavos: number,
  freightCentavos: number,
  treatment: QuoteVatTreatment,
  vatRate: string | null,
): { goods: number; vat: number; total: number } {
  const base = goodsCentavos + freightCentavos;
  const rate = vatRate ? Number(vatRate) : null;
  const vat =
    treatment === 'none' || rate === null || goodsCentavos === 0
      ? 0
      : treatment === 'inclusive'
        ? Math.round((base * rate) / (1 + rate))
        : Math.round(base * rate);
  return { goods: goodsCentavos, vat, total: treatment === 'exclusive' ? base + vat : base };
}

/** RFQ quantities arrive as 4 dp ("1000.0000"); offers are 3 dp. */
export const trimQuantity = (q: string): string => q.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
