/**
 * Returns a submit-button label that appends an ellipsis when the action is pending.
 *
 *   actionLabel('Save', isPending) // "Save" or "Save…"
 */
export function actionLabel(base: string, isPending: boolean): string {
  return isPending ? `${base}…` : base;
}
