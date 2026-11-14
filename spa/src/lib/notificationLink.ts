import { expandAppPath } from '@/lib/appPaths';

export function notificationLinkTo(value: unknown, prefix?: string): string | null {
 if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) return null;

 let target: URL;
 try {
  target = new URL(value, 'https://ogami.invalid');
 } catch {
  return null;
 }

 if (target.origin !== 'https://ogami.invalid') return null;

 const legacyTargetPath = expandAppPath(target.pathname);
 const scope = prefix ? expandAppPath(prefix.replace(/\/+$/, '')) : undefined;
 if (scope && legacyTargetPath !== scope && !legacyTargetPath.startsWith(`${scope}/`)) return null;
 if (!scope && (legacyTargetPath === '/portal' || legacyTargetPath.startsWith('/portal/'))) return null;

 return `${target.pathname}${target.search}${target.hash}`;
}
