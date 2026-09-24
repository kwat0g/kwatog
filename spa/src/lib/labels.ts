/**
 * Returns a submit-button label that appends an ellipsis when the action is pending.
 *
 * actionLabel('Save', isPending) // "Save" or "Save…"
 */
export function actionLabel(base: string, isPending: boolean): string {
 return isPending ? `${base}…` : base;
}

const AGING_BUCKET_LABELS: Record<string, string> = {
 current: 'Current',
 d1_30: '1–30 days',
 d31_60: '31–60 days',
 d61_90: '61–90 days',
 d91_plus: '91+ days',
};

/**
 * Display label for a bill/invoice `aging_bucket`. The API reports drafts,
 * paid and cancelled documents as `paid` (not aging), shown as a dash.
 */
export function agingBucketLabel(bucket: string | null | undefined): string {
 return (bucket && AGING_BUCKET_LABELS[bucket]) ?? '—';
}
