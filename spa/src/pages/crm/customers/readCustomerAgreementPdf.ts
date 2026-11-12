import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

export const CUSTOMER_AGREEMENT_PDF_MAX_BYTES = 10 * 1024 * 1024;
export const CUSTOMER_AGREEMENT_PDF_MAX_PAGES = 60;
const MAX_EXTRACTED_CHARACTERS = 150_000;

interface PositionedTextItem {
  str: string;
  transform?: number[];
  hasEOL?: boolean;
}

interface TextRow {
  y: number;
  items: Array<{ x: number; text: string }>;
}

function positionedRows(items: unknown[]): string[] {
  const rows: TextRow[] = [];
  for (const item of items) {
    if (!item || typeof item !== 'object' || !('str' in item)) continue;
    const textItem = item as PositionedTextItem;
    const text = textItem.str.trim();
    if (!text) continue;
    const x = Number(textItem.transform?.[4] ?? 0);
    const y = Number(textItem.transform?.[5] ?? 0);
    let row = rows.find((candidate) => Math.abs(candidate.y - y) < 2.5);
    if (!row) {
      row = { y, items: [] };
      rows.push(row);
    }
    row.items.push({ x, text });
  }

  return rows
    .sort((left, right) => right.y - left.y)
    .map((row) => {
      const ordered = row.items.sort((left, right) => left.x - right.x);
      return ordered.reduce((line, part, index) => {
        if (index === 0) return part.text;
        const separator = part.x - ordered[index - 1].x > 120 ? ' | ' : ' ';
        return `${line}${separator}${part.text}`;
      }, '');
    });
}

export async function readCustomerAgreementPdf(file: File): Promise<string> {
  if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
    throw new Error('Choose a PDF file.');
  }
  if (file.size > CUSTOMER_AGREEMENT_PDF_MAX_BYTES) {
    throw new Error('Each PDF must be 10 MB or smaller.');
  }

  const prefix = new TextDecoder('latin1').decode(await file.slice(0, 1024).arrayBuffer());
  if (!prefix.includes('%PDF-')) {
    throw new Error('This file is not a valid PDF. Choose an agreement or warranty PDF.');
  }

  const pdfjs = await import('pdfjs-dist');
  // The same worker URL may already be cached with nginx's former octet-stream
  // MIME type. A versioned query gives browsers and the service worker a fresh key.
  const workerUrl = new URL(pdfWorkerUrl, window.location.href);
  workerUrl.searchParams.set('mime', 'javascript-v1');
  pdfjs.GlobalWorkerOptions.workerSrc = workerUrl.href;
  const document = await pdfjs.getDocument({
    data: new Uint8Array(await file.arrayBuffer()),
    isEvalSupported: false,
  }).promise;

  try {
    if (document.numPages > CUSTOMER_AGREEMENT_PDF_MAX_PAGES) {
      throw new Error(`Each PDF must have ${CUSTOMER_AGREEMENT_PDF_MAX_PAGES} pages or fewer.`);
    }
    const pages: string[] = [];
    let characterCount = 0;
    for (let pageNumber = 1; pageNumber <= document.numPages; pageNumber += 1) {
      const page = await document.getPage(pageNumber);
      const content = await page.getTextContent();
      const pageText = positionedRows(content.items);
      const pageContent = pageText.join('\n');
      characterCount += pageContent.length;
      pages.push(pageContent);
      if (characterCount >= MAX_EXTRACTED_CHARACTERS) break;
    }

    const text = pages.join('\n').slice(0, MAX_EXTRACTED_CHARACTERS).trim();
    if (!text) throw new Error('This PDF has no selectable text. Scanned or image-only PDFs cannot be verified or imported.');
    return text;
  } finally {
    await document.destroy();
  }
}
