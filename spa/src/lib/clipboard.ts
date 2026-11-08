/**
 * Copy text to the clipboard.
 *
 * The async Clipboard API is preferred. It is unavailable on insecure
 * origins and can be denied by browser permission settings or an embedding
 * page's Permissions-Policy, so fall back to the legacy execCommand path in
 * those cases. The fallback must run while the click's user activation is
 * still valid, which is why it is invoked immediately after the API rejects.
 */
export async function copyText(value: string): Promise<boolean> {
  try {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(value);
      return true;
    }
  } catch {
    // Fall through to the legacy path.
  }

  return copyTextFallback(value);
}

function copyTextFallback(value: string): boolean {
  if (typeof document === 'undefined' || typeof document.execCommand !== 'function') {
    return false;
  }

  const textarea = document.createElement('textarea');
  textarea.value = value;
  textarea.setAttribute('readonly', '');
  textarea.setAttribute('aria-hidden', 'true');
  textarea.style.position = 'fixed';
  textarea.style.top = '0';
  textarea.style.left = '0';
  textarea.style.width = '1px';
  textarea.style.height = '1px';
  textarea.style.padding = '0';
  textarea.style.border = '0';
  textarea.style.outline = '0';
  textarea.style.boxShadow = 'none';
  textarea.style.background = 'transparent';
  textarea.style.opacity = '0';

  const selection = document.getSelection();
  const previousRange = selection && selection.rangeCount > 0 ? selection.getRangeAt(0).cloneRange() : null;
  const activeElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;

  document.body.appendChild(textarea);

  try {
    textarea.focus({ preventScroll: true });
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);
    return document.execCommand('copy');
  } catch {
    return false;
  } finally {
    textarea.remove();
    if (selection) {
      selection.removeAllRanges();
      if (previousRange) selection.addRange(previousRange);
    }
    if (activeElement?.isConnected) activeElement.focus({ preventScroll: true });
  }
}
