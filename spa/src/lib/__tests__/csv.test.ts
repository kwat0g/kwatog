import { describe, it, expect } from 'vitest';
import { toCsv, slugifyFilename } from '../csv';

describe('toCsv', () => {
  it('joins header and rows with CRLF', () => {
    expect(toCsv(['a', 'b'], [[1, 2]])).toBe('a,b\r\n1,2');
  });

  it('leaves numbers unquoted so a spreadsheet reads them as figures', () => {
    expect(toCsv(['amount'], [[1234.5]])).toBe('amount\r\n1234.5');
    expect(toCsv(['amount'], [[-5]])).toBe('amount\r\n-5');
  });

  it('quotes commas, quotes and newlines', () => {
    expect(toCsv(['x'], [['a,b']])).toBe('x\r\n"a,b"');
    expect(toCsv(['x'], [['say "hi"']])).toBe('x\r\n"say ""hi"""');
    expect(toCsv(['x'], [['line 1\nline 2']])).toBe('x\r\n"line 1\nline 2"');
  });

  it('renders null and undefined as empty cells, not "null"', () => {
    expect(toCsv(['a', 'b'], [[null, undefined]])).toBe('a,b\r\n,');
  });

  it('neutralises spreadsheet formulas in string cells', () => {
    expect(toCsv(['name'], [['=HYPERLINK("http://x")']])).toBe(
      'name\r\n"\'=HYPERLINK(""http://x"")"',
    );
    expect(toCsv(['name'], [['+SUM(A1)']])).toBe("name\r\n'+SUM(A1)");
    expect(toCsv(['name'], [['@cmd']])).toBe("name\r\n'@cmd");
    expect(toCsv(['name'], [['-2+3']])).toBe("name\r\n'-2+3");
  });

  it('does not prefix a negative NUMBER — the guard is for strings only', () => {
    expect(toCsv(['delta'], [[-12]])).toBe('delta\r\n-12');
  });

  it('keeps a formula-looking value readable after the apostrophe', () => {
    // The apostrophe is exported, so Excel shows it in the formula bar but not
    // in the cell; a re-import of our own file therefore round-trips the text.
    expect(toCsv(['x'], [['=1+1']])).toBe("x\r\n'=1+1");
  });
});

describe('slugifyFilename', () => {
  it('lowercases and hyphenates', () => {
    expect(slugifyFilename('Open NCRs by Age')).toBe('open-ncrs-by-age');
  });

  it('drops path separators and punctuation', () => {
    expect(slugifyFilename('AR/AP: aging (30d)')).toBe('ar-ap-aging-30d');
  });

  it('never returns an empty filename stem', () => {
    expect(slugifyFilename('***')).toBe('export');
  });
});
