// Archive with an undo, instead of a confirmation dialog.
//
// Every master-data list in the app soft-deletes behind an ArchiveFilter, and
// every one of them gated that on a ConfirmDialog. A modal is the right price
// for an irreversible action; for a one-click change with a `restore` endpoint
// sitting right next to it, it is a toll booth on the happy path — twelve lists
// asking "are you sure?" about something that is trivially undone.
//
// `lib/undoToast.tsx` was written for exactly this and had no consumers.
//
// The line this draws: master data behind an ArchiveFilter gets an undo;
// document-level actions (voiding a journal entry, cancelling a delivery) keep
// their confirmation, because those are not reversible by a restore call.

import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { showUndoToast } from '@/lib/undoToast';
import { reportMutationError } from '@/lib/formErrors';

interface Args {
  /** Archives the record. Usually `api.delete`. */
  archive: (id: string) => Promise<unknown>;
  /** Puts it back. Usually `api.restore`. */
  restore: (id: string) => Promise<unknown>;
  /** Invalidated after both archive and undo. */
  invalidateKey: QueryKey;
  /** Singular noun for the toast: "position", "shift", "leave type". */
  noun: string;
  /** Human name of the record, for the toast text. */
  nameOf?: (id: string) => string | undefined;
  /** Extra cleanup after a successful archive (clearing a selection, say). */
  afterArchive?: () => void;
}

export function useArchiveWithUndo({
  archive,
  restore,
  invalidateKey,
  noun,
  nameOf,
  afterArchive,
}: Args) {
  const queryClient = useQueryClient();
  const invalidate = () => void queryClient.invalidateQueries({ queryKey: invalidateKey });

  const undo = useMutation({
    mutationFn: (id: string) => restore(id),
    onSuccess: () => {
      invalidate();
      toast.success(`${noun[0].toUpperCase()}${noun.slice(1)} restored.`);
    },
    // If the undo itself fails the record really is archived, and saying so is
    // better than a silent no-op that leaves the user believing they reversed it.
    onError: (err) => reportMutationError(err, `Could not restore the ${noun}.`),
  });

  const run = useMutation({
    mutationFn: (id: string) => archive(id),
    onSuccess: (_data, id) => {
      invalidate();
      afterArchive?.();
      const name = nameOf?.(id);
      showUndoToast({
        message: name ? `${name} archived.` : `${noun[0].toUpperCase()}${noun.slice(1)} archived.`,
        onUndo: () => undo.mutate(id),
      });
    },
    onError: (err) => reportMutationError(err, `Could not archive the ${noun}.`),
  });

  return { archive: run, undo };
}
