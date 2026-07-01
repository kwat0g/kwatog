import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { isAxiosError } from 'axios';
import { driverApi } from '@/api/driver';

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
    return <div className="py-12 text-center text-zinc-500">Missing delivery id.</div>;
  }

  const onPickFile = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0];
    // Reset the input so picking the same file again still fires onChange.
    e.target.value = '';
    if (!f) {
      setHint('No photo selected. Tap "Take Photo" or "Choose from Gallery" to try again.');
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
    <div className="space-y-4">
      <Link
        to={`/driver/${id}`}
        className="inline-block text-sm text-zinc-500 underline min-h-[44px] py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded"
      >
        ← Back to delivery
      </Link>
      <h1 className="text-lg font-semibold">Receipt Photo</h1>

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

      {preview ? (
        <img src={preview} alt="receipt preview" className="w-full rounded-lg" />
      ) : (
        <div className="aspect-[4/3] rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-700 flex items-center justify-center text-zinc-500">
          No photo yet
        </div>
      )}

      {hint && (
        <div className="text-sm text-warning" role="status">
          {hint}
        </div>
      )}

      <div className="grid grid-cols-2 gap-2">
        <button
          type="button"
          onClick={() => fileRef.current?.click()}
          className="rounded-lg border border-zinc-300 dark:border-zinc-700 py-3 min-h-[44px] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          {preview ? 'Retake' : 'Take Photo'}
        </button>
        <button
          type="button"
          onClick={() => galleryRef.current?.click()}
          className="rounded-lg border border-zinc-300 dark:border-zinc-700 py-3 min-h-[44px] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          Choose from Gallery
        </button>
      </div>

      <button
        type="button"
        disabled={!file || upload.isPending}
        onClick={() => upload.mutate()}
        className="w-full rounded-lg bg-indigo-600 text-white py-3 font-medium disabled:opacity-60 min-h-[44px] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
      >
        {upload.isPending ? 'Uploading…' : 'Upload Photo'}
      </button>
    </div>
  );
}
