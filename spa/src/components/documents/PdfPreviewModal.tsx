/**
 * Series E (E1/E3) — in-browser PDF preview backed by the document vault.
 * Opens the inline-served vault URL inside a sandboxed iframe rather than
 * redirecting to a new tab so the user keeps their topbar/breadcrumbs.
 *
 * Confidential PDFs return Cache-Control: no-store from the API, so the
 * browser will not retain them in disk cache after the modal closes.
 */

import { LuDownload } from '@/lib/icons';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { downloadAuthenticatedFile } from '@/api/download';
import type { DocumentRecord } from '@/types/documents';

interface PdfPreviewModalProps {
 isOpen: boolean;
 onClose: () => void;
 document: DocumentRecord | null;
}

export function PdfPreviewModal({ isOpen, onClose, document }: PdfPreviewModalProps) {
 if (!document) return null;

 return (
 <Modal isOpen={isOpen} onClose={onClose} size="xl" title={document.document_label}>
 <div className="flex flex-col gap-3 h-[70vh]">
 <iframe
 // sandbox restricts what the inlined PDF viewer can do; we keep
 // 'allow-same-origin' so the cookie travels with the request.
 src={document.view_url}
 title={document.file_name}
 className="w-full h-full border border-default rounded-md bg-canvas"
 sandbox="allow-same-origin allow-scripts allow-popups"
 />
 <div className="flex items-center justify-between text-xs text-muted">
 <div className="flex items-center gap-2">
 <span className="font-mono tabular-nums">{document.file_name}</span>
 {document.is_confidential && (
 <span className="px-1.5 py-0.5 rounded bg-danger-bg text-danger-fg text-2xs font-medium uppercase tracking-wider">
 Confidential
 </span>
 )}
 </div>
 <Button variant="primary" size="sm" icon={<LuDownload size={14} />}
 onClick={() => void downloadAuthenticatedFile(document.download_url, { filename: document.file_name, errorMessage: 'Failed to download document.' })}>
 Download
 </Button>
 </div>
 </div>
 </Modal>
 );
}
