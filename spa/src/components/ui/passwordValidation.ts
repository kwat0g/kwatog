import type { PasswordPolicy } from '@/api/auth';

export const DEFAULT_PASSWORD_POLICY: PasswordPolicy = {
  minimum_length: 8,
  requires_uppercase: true,
  requires_lowercase: true,
  requires_digit: true,
  requires_special: true,
};

export function passwordMinimumLength(policy?: PasswordPolicy): number {
  return policy?.minimum_length ?? DEFAULT_PASSWORD_POLICY.minimum_length;
}

export interface PasswordRequirement {
  key: string;
  label: string;
  passed: boolean;
}

export function passwordRequirements(
  password: string,
  policy?: PasswordPolicy,
): PasswordRequirement[] {
  const effective = policy ?? DEFAULT_PASSWORD_POLICY;
  const requirements: PasswordRequirement[] = [
    {
      key: 'length',
      label: `At least ${effective.minimum_length} characters`,
      passed: password.length >= effective.minimum_length,
    },
  ];

  if (effective.requires_uppercase) {
    requirements.push({ key: 'uppercase', label: 'An uppercase letter', passed: /[A-Z]/.test(password) });
  }
  if (effective.requires_lowercase) {
    requirements.push({ key: 'lowercase', label: 'A lowercase letter', passed: /[a-z]/.test(password) });
  }
  if (effective.requires_digit) {
    requirements.push({ key: 'digit', label: 'A digit', passed: /[0-9]/.test(password) });
  }
  if (effective.requires_special) {
    requirements.push({ key: 'special', label: 'A special character', passed: /[^A-Za-z0-9]/.test(password) });
  }

  return requirements;
}

export function isStrongPassword(password: string, policy?: PasswordPolicy): boolean {
  return passwordRequirements(password, policy).every((requirement) => requirement.passed);
}
