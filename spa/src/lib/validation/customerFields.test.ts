import { describe, expect, it } from 'vitest';
import {
  customerAddressField,
  customerContactNameField,
  customerEmailField,
  customerLegalNameField,
  customerPhoneField,
  customerWarrantyAdditionalTermsField,
  customerWarrantyLabelField,
  customerWarrantyOtherIssueField,
  validIsoCalendarDate,
} from './customerFields';

describe('customer input validation', () => {
  it('normalizes a legal company name and accepts common legal punctuation', () => {
    expect(customerLegalNameField.parse('  Honda   Philippines, Inc.  ')).toBe('Honda Philippines, Inc.');
  });

  it.each(['New customer', 'Customer 1', 'Company name', 'asdf', 'AAAA', '<script>alert(1)</script>'])('rejects placeholder or malformed customer names: %s', (name) => {
    expect(customerLegalNameField.safeParse(name).success).toBe(false);
  });

  it('normalizes contact names and email while rejecting malformed values', () => {
    expect(customerContactNameField.parse("  Mika   O'Connor  ")).toBe("Mika O'Connor");
    expect(customerEmailField.parse('  SALES@HONDA.COM.PH ')).toBe('sales@honda.com.ph');
    expect(customerContactNameField.safeParse('Jane 99').success).toBe(false);
    expect(customerEmailField.safeParse('not-an-email').success).toBe(false);
  });

  it('accepts a formatted phone and rejects alphabetic, too-short, or repeated-digit values', () => {
    expect(customerPhoneField.parse(' +63 (917) 123-4567 ')).toBe('+63 (917) 123-4567');
    expect(customerPhoneField.safeParse('call me maybe').success).toBe(false);
    expect(customerPhoneField.safeParse('12345').success).toBe(false);
    expect(customerPhoneField.safeParse('1111111').success).toBe(false);
  });

  it('keeps readable multiline addresses and rejects markup-only values', () => {
    expect(customerAddressField.parse('  Honda Plant,\n  Santa Rosa, Laguna  ')).toBe('Honda Plant,\nSanta Rosa, Laguna');
    expect(customerAddressField.safeParse('<script>abc</script>').success).toBe(false);
    expect(customerAddressField.safeParse('unknown').success).toBe(false);
  });

  it('rejects impossible calendar dates instead of comparing malformed date strings', () => {
    expect(validIsoCalendarDate('2028-02-29')).toBe(true);
    expect(validIsoCalendarDate('2026-02-29')).toBe(false);
    expect(validIsoCalendarDate('2026-13-01')).toBe(false);
  });

  it('validates product warranty labels, other issues, and plain-text agreement terms', () => {
    expect(customerWarrantyLabelField.parse('  12-month   standard ')).toBe('12-month standard');
    expect(customerWarrantyLabelField.safeParse('sample').success).toBe(false);
    expect(customerWarrantyOtherIssueField.parse('Thread corrosion')).toBe('Thread corrosion');
    expect(customerWarrantyOtherIssueField.safeParse('asdf').success).toBe(false);
    expect(customerWarrantyAdditionalTermsField.parse('  Replace the affected part.  ')).toBe('Replace the affected part.');
    expect(customerWarrantyAdditionalTermsField.safeParse('<script>alert(1)</script>').success).toBe(false);
  });
});
