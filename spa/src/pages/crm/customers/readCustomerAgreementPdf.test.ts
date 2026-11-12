import { beforeEach, describe, expect, it, vi } from 'vitest';

const pdfjsMock = vi.hoisted(() => ({
  options: { workerSrc: '' },
  getDocument: vi.fn(),
}));

vi.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: pdfjsMock.options,
  getDocument: pdfjsMock.getDocument,
}));

import { readCustomerAgreementPdf } from './readCustomerAgreementPdf';

describe('readCustomerAgreementPdf', () => {
  beforeEach(() => {
    pdfjsMock.options.workerSrc = '';
    pdfjsMock.getDocument.mockReset();
    pdfjsMock.getDocument.mockReturnValue({
      promise: Promise.resolve({
        numPages: 1,
        getPage: async () => ({
          getTextContent: async () => ({ items: [{ str: 'Customer Supply Agreement', transform: [1, 0, 0, 1, 0, 100] }] }),
        }),
        destroy: async () => undefined,
      }),
    });
  });

  it('uses a fresh module-worker URL so browsers do not reuse the old wrong-MIME cache entry', async () => {
    const file = new File(['%PDF-1.4'], 'agreement.pdf', { type: 'application/pdf' });

    await readCustomerAgreementPdf(file);

    const workerUrl = new URL(pdfjsMock.options.workerSrc, window.location.href);
    expect(workerUrl.searchParams.get('mime')).toBe('javascript-v1');
  });
});
