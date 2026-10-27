export function notificationLinkTo(value: unknown, prefix?: string): string | null {
 if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) return null;

 let target: URL;
 try {
  target = new URL(value, 'https://ogami.invalid');
 } catch {
  return null;
 }

 if (target.origin !== 'https://ogami.invalid') return null;

 const scope = prefix?.replace(/\/+$/, '');
 if (scope && target.pathname !== scope && !target.pathname.startsWith(`${scope}/`)) return null;
 if (!scope && (target.pathname === '/portal' || target.pathname.startsWith('/portal/'))) return null;

 return `${target.pathname}${target.search}${target.hash}`;
}
