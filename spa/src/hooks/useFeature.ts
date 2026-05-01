import { useAuthStore } from '@/stores/authStore';

export function useFeature(slug: string): boolean {
  const features = useAuthStore((s) => s.features);
  return features.has(slug);
}
