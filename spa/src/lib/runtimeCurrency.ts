let functionalCurrency: string | null = null;
const listeners = new Set<() => void>();

export function setFunctionalCurrency(code: string | null | undefined): void {
  const normalized = String(code ?? '').trim().toUpperCase();
  functionalCurrency = /^[A-Z]{3}$/.test(normalized) ? normalized : null;
  listeners.forEach((listener) => listener());
}

export function getFunctionalCurrency(): string | null {
  return functionalCurrency;
}

export function subscribeFunctionalCurrency(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
