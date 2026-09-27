/**
 * Receipt photo — the last step of a delivered run.
 *
 * Two file inputs on purpose: `capture="environment"` opens the camera app on a
 * phone, the plain one opens the gallery. Both write into the same preview, so
 * the driver can retake or pick an existing shot before committing the upload.
 */
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type ChangeEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { isAxiosError } from 'axios';
import { LuCamera, LuImage, LuTriangleAlert } from '@/lib/icons';
import { driverApi } from '@/api/driver';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';

const MAX_BYTES = 8 * 1024 * 1024; // mirrors backend image|max:8192

function describeUploadError(err: unknown): string {
 if (isAxiosError(err) && err.response) {
 if (err.response.status === 422) {
 const errors = err.response.data?.errors as Record<string, string[]> | undefined;
 const first = errors ? Object.values(errors)[0]?.[0] : undefined;
 if (first) return first;
 const msg = err.response.data?.message;
 if (typeof msg === 'string' && msg.length > 0) return msg;
 }
 if (err.response.status === 413) return 'Photo is too large. Try a smaller image.';
 if (err.response.status === 404) return 'Delivery not found or no longer assigned to you.';
 }
 return 'Upload failed.';
}

function describeSize(bytes: number): string {
 return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
}

export default function DriverPhotoCapture() {
 const { id } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const fileRef = useRef<HTMLInputElement>(null);
 const galleryRef = useRef<HTMLInputElement>(null);
 const [preview, setPreview] = useState<string | null>(null);
 const [file, setFile] = useState<File | null>(null);
 const [hint, setHint] = useState<string | null>(null);

 // Revoke previous blob URL whenever preview changes, and on unmount.
 useEffect(() => {
 return () => {
 if (preview) URL.revokeObjectURL(preview);
 };
 }, [preview]);

 const upload = useMutation({
 mutationFn: () => {
 if (!file || !id) throw new Error('no file');
 return driverApi.uploadReceipt(id, file);
 },
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['driver'] });
 toast.success('Receipt uploaded.');
 if (id) navigate(`/driver/${id}`);
 },
 onError: (err) => toast.error(describeUploadError(err)),
 });

 if (!id) {
 return (
 <div>
 <PageHeader title="Receipt photo" backTo="/driver" backLabel="My deliveries" />
 <EmptyState icon="alert-circle" title="Missing delivery" description="Open the delivery again from your list and retry the upload." />
 </div>
 );
 }

 const onPickFile = (e: ChangeEvent<HTMLInputElement>) => {
 const f = e.target.files?.[0];
 // Reset the input so picking the same file again still fires onChange.
 e.target.value = '';
 if (!f) {
 setHint('No photo selected. Tap "Take photo" or "Choose from gallery" to try again.');
 return;
 }
 if (!f.type.startsWith('image/')) {
 setHint('That file is not an image. Please pick a photo.');
 return;
 }
 if (f.size > MAX_BYTES) {
 setHint(`Photo is too large (${(f.size / (1024 * 1024)).toFixed(1)} MB). Maximum 8 MB.`);
 return;
 }
 setHint(null);
 setFile(f);
 setPreview(URL.createObjectURL(f));
 };

 return (
 <div>
 <PageHeader
 title="Receipt photo"
 subtitle="Signed receipt or delivery photo"
 backTo={`/driver/${id}`}
 backLabel="Back to delivery"
 />

 <div className="max-w-2xl space-y-4 px-5 py-4">
 <Panel title="Capture the proof">
 <p className="text-sm text-secondary">
 Photograph the signed receipt, or the delivered goods with the customer&apos;s name visible. The
 office uses it to confirm the delivery against your report.
 </p>

 <input
 ref={fileRef}
 type="file"
 accept="image/*"
 capture="environment"
 className="hidden"
 onChange={onPickFile}
 />
 <input
 ref={galleryRef}
 type="file"
 accept="image/*"
 className="hidden"
 onChange={onPickFile}
 />

 <div className="mt-3 overflow-hidden rounded-md border border-default bg-surface">
 {preview ? (
 <img src={preview} alt="Receipt preview" className="max-h-[60vh] w-full object-contain" />
 ) : (
 <div className="flex aspect-[4/3] flex-col items-center justify-center gap-2 text-muted">
 <LuCamera size={28} aria-hidden />
 <span className="text-sm">No photo yet</span>
 </div>
 )}
 </div>

 {file && (
 <p className="mt-2 text-xs text-muted">
 <span className="font-mono tabular-nums">{describeSize(file.size)}</span> · ready to upload
 </p>
 )}

 {hint && (
 <div className="mt-3 flex items-start gap-2 rounded-md bg-warning-bg px-3 py-2 text-sm text-warning-fg" role="status">
 <LuTriangleAlert size={14} className="mt-0.5 shrink-0" aria-hidden />
 <span>{hint}</span>
 </div>
 )}

 <div className="mt-4 grid grid-cols-2 gap-2">
 <Button
 variant="secondary"
 size="touch"
 icon={<LuCamera size={14} />}
 onClick={() => fileRef.current?.click()}
 >
 {preview ? 'Retake' : 'Take photo'}
 </Button>
 <Button
 variant="secondary"
 size="touch"
 icon={<LuImage size={14} />}
 onClick={() => galleryRef.current?.click()}
 >
 Choose from gallery
 </Button>
 </div>

 <Button
 variant="primary"
 size="touch"
 className="mt-2 w-full"
 loading={upload.isPending}
 disabled={!file}
 onClick={() => upload.mutate()}
 >
 Upload photo
 </Button>

 <p className="mt-3 text-xs text-subtle">
 Images up to 8 MB. The photo is stored against this delivery and is visible to the office.
 </p>
 </Panel>
 </div>
 </div>
 );
}
