// The banner half of `useFormSafety`. Renders nothing until a draft exists, so
// it is safe to drop unconditionally at the top of any form.
//
// Exists so adopting draft-recovery is one element rather than three props
// threaded from the hook — that friction is why the underlying
// DraftRestoreBanner reached one page out of ~55.

import { DraftRestoreBanner } from './DraftRestoreBanner';
import type { FormSafety } from '@/hooks/useFormSafety';

export function FormDraftBanner({
  safety,
  /**
   * DraftRestoreBanner carries its own `mx-5 mt-4`, which is right directly
   * under a PageHeader and doubles the inset inside a form that already has
   * `px-5`. Set false there.
   */
  inset = true,
}: {
  safety: FormSafety;
  inset?: boolean;
}) {
  const { hasDraft, draftAge, restore, discard } = safety.draftState;
  if (!hasDraft) return null;
  const banner = <DraftRestoreBanner ageMs={draftAge} onRestore={restore} onDiscard={discard} />;
  return inset ? banner : <div className="-mx-5 mb-3">{banner}</div>;
}
