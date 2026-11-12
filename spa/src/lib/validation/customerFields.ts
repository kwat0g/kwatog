import { z } from 'zod';

const LEGAL_NAME_PATTERN = /^[\p{L}\p{N}][\p{L}\p{N}\p{M} .,&()/+#\u0027-]*$/u;
const PERSON_NAME_PATTERN = /^[\p{L}\p{M}][\p{L}\p{M} .\u0027-]*$/u;
const ROLE_TITLE_PATTERN = /^[\p{L}\p{N}][\p{L}\p{N}\p{M} .,&()/+\u0027-]*$/u;
const WARRANTY_LABEL_PATTERN = /^[\p{L}\p{N}][\p{L}\p{N}\p{M} .,&();:/+\u0027-]*$/u;
const ADDRESS_PATTERN = /^[\p{L}\p{N}#][\p{L}\p{N}\p{M}\s.,#&()/:+\u0027-]*$/u;
const TAX_ID_PATTERN = /^[\p{L}\p{N}][\p{L}\p{N} ./-]*$/u;
const PHONE_PATTERN = /^\+?[0-9][0-9(). -]*$/;

const PLACEHOLDER_NAMES = new Set([
  'abc', 'address', 'asdf', 'company', 'companyname', 'contact', 'contactperson', 'customer', 'customer1', 'customername',
  'democompany', 'democustomer', 'dummy', 'entername', 'name', 'newcustomer',
  'email', 'entercontact', 'enterphone', 'noaddress', 'notprovided', 'placeholder', 'phone',
  'qwerty', 'sample', 'samplecompany', 'samplecustomer', 'test', 'testcompany',
  'testcustomer', 'unknown', 'youraddress', 'yourcontact', 'youremail', 'yourname', 'yourphone',
]);

export function normalizeCustomerText(value: string, multiline = false): string {
  const withoutControls = value
    .normalize('NFC')
    .replace(/[\p{Cc}\p{Cf}]/gu, (character) => ['\n', '\r', '\t'].includes(character) ? character : '')
    .replace(/\r\n?/gu, '\n');

  if (!multiline) return withoutControls.replace(/\s+/gu, ' ').trim();

  return withoutControls
    .split('\n')
    .map((line) => line.replace(/[\t ]+/gu, ' ').trim())
    .join('\n')
    .replace(/\n{3,}/gu, '\n\n')
    .trim();
}

export function isPlaceholderCustomerValue(value: string): boolean {
  const key = value.normalize('NFKD').toLowerCase().replace(/\p{M}/gu, '').replace(/[^\p{L}\p{N}]/gu, '');
  return PLACEHOLDER_NAMES.has(key) || /^(.)\1{3,}$/u.test(key);
}

function optionalTextField({
  maximum,
  minimum = 2,
  pattern,
  label,
  multiline = false,
  rejectPlaceholder = false,
}: {
  maximum: number;
  minimum?: number;
  pattern: RegExp;
  label: string;
  multiline?: boolean;
  rejectPlaceholder?: boolean;
}) {
  return z.string()
    .transform((value) => normalizeCustomerText(value, multiline))
    .pipe(z.string().superRefine((value, context) => {
      if (value.length === 0) return;
      if (value.length < minimum) {
        context.addIssue({ code: 'custom', message: `${label} must be at least ${minimum} characters.` });
      }
      if (value.length > maximum) {
        context.addIssue({ code: 'custom', message: `${label} must be ${maximum} characters or fewer.` });
      }
      if (!pattern.test(value)) {
        context.addIssue({ code: 'custom', message: `${label} contains unsupported characters.` });
      }
      if (rejectPlaceholder && isPlaceholderCustomerValue(value)) {
        context.addIssue({ code: 'custom', message: `Enter the actual ${label.toLowerCase()}, not a placeholder.` });
      }
    }))
    .optional();
}

export const customerLegalNameField = z.string()
  .transform((value) => normalizeCustomerText(value))
  .pipe(z.string()
    .min(2, 'Enter the customer’s full business name.')
    .max(200, 'Customer name must be 200 characters or fewer.')
    .regex(LEGAL_NAME_PATTERN, 'Use a business name with letters, numbers, spaces, or standard name punctuation.')
    .refine((value) => !isPlaceholderCustomerValue(value), 'Enter the actual customer name, not a placeholder.'));

export const customerContactNameField = optionalTextField({
  maximum: 100, pattern: PERSON_NAME_PATTERN, label: 'Contact name', rejectPlaceholder: true,
});

export const customerRoleTitleField = optionalTextField({
  maximum: 100, pattern: ROLE_TITLE_PATTERN, label: 'Contact role or title', rejectPlaceholder: true,
});

export const customerAddressField = optionalTextField({
  maximum: 500, minimum: 5, pattern: ADDRESS_PATTERN, label: 'Address', multiline: true, rejectPlaceholder: true,
});

export const customerTaxIdField = optionalTextField({
  maximum: 20, minimum: 4, pattern: TAX_ID_PATTERN, label: 'Tax identification number', rejectPlaceholder: true,
});

export const customerWarrantyLabelField = z.string()
  .transform((value) => normalizeCustomerText(value))
  .pipe(z.string()
    .min(2, 'Enter the warranty option name.')
    .max(120, 'Warranty option name must be 120 characters or fewer.')
    .regex(WARRANTY_LABEL_PATTERN, 'Use letters, numbers, spaces, or standard warranty label punctuation.')
    .refine((value) => !isPlaceholderCustomerValue(value), 'Enter the actual warranty option name, not a placeholder.'));

export const customerWarrantyOtherIssueField = optionalTextField({
  maximum: 500,
  pattern: /^[\p{L}\p{N}][\p{L}\p{N}\p{M}\s.,;:!?&()/+#\u0027-]*$/u,
  label: 'Other covered issue',
  rejectPlaceholder: true,
});

export const customerWarrantyAdditionalTermsField = z.string()
  .transform((value) => normalizeCustomerText(value, true))
  .pipe(z.string().max(2000, 'Additional warranty terms must be 2,000 characters or fewer.')
    .refine((value) => !/<\/?[a-z][^>]*>/iu.test(value), 'Enter warranty terms as plain text without HTML markup.'))
  .optional();

export const customerEmailField = z.string()
  .transform((value) => normalizeCustomerText(value).toLowerCase())
  .pipe(z.string().superRefine((value, context) => {
    if (!value) return;
    if (value.length > 200) {
      context.addIssue({ code: 'custom', message: 'Email must be 200 characters or fewer.' });
    }
    if (!z.string().email().safeParse(value).success) {
      context.addIssue({ code: 'custom', message: 'Enter a valid email address.' });
    }
  }))
  .optional();

export const customerPhoneField = z.string()
  .transform((value) => normalizeCustomerText(value))
  .pipe(z.string().superRefine((value, context) => {
    if (!value) return;
    const digits = value.replace(/\D/gu, '');
    if (value.length > 20 || !PHONE_PATTERN.test(value) || digits.length < 7 || digits.length > 15) {
      context.addIssue({ code: 'custom', message: 'Enter a valid phone number with 7 to 15 digits.' });
    } else if (/^(.)\1+$/u.test(digits)) {
      context.addIssue({ code: 'custom', message: 'Enter a real phone number, not repeated placeholder digits.' });
    }
  }))
  .optional();

export function validIsoCalendarDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/u.exec(value);
  if (!match) return false;
  const [, yearText, monthText, dayText] = match;
  return checkCalendarDate(Number(yearText), Number(monthText), Number(dayText));
}

function checkCalendarDate(year: number, month: number, day: number): boolean {
  if (year < 1 || month < 1 || month > 12 || day < 1) return false;
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
  return day <= daysInMonth;
}

export const customerAgreementDateField = z.string()
  .transform((value) => normalizeCustomerText(value))
  .pipe(z.string().superRefine((value, context) => {
    if (value && !validIsoCalendarDate(value)) {
      context.addIssue({ code: 'custom', message: 'Enter a real calendar date.' });
    }
  }))
  .optional();

export const customerCreditLimitField = z.preprocess(
  (value) => value === '' || value == null ? undefined : value,
  z.coerce.number()
    .min(0, 'Credit limit cannot be negative.')
    .max(99999999.99, 'Credit limit exceeds the supported maximum.')
    .refine((value) => Math.abs(value * 100 - Math.round(value * 100)) < 1e-8, 'Credit limit can have at most two decimal places.')
    .optional(),
);

export const customerWarrantyDaysField = z.preprocess(
  (value) => value === '' || value == null ? undefined : value,
  z.coerce.number().int('Enter a whole number of days.').min(0).max(3650).optional(),
);

export const customerPaymentTermsField = z.coerce.number()
  .int('Payment terms must be a whole number of days.')
  .min(0)
  .max(365);
