import { describe, expect, it } from 'vitest';
import { isStrongPassword, passwordRequirements } from './passwordValidation';

describe('password requirements', () => {
  it('requires every live policy criterion', () => {
    expect(isStrongPassword('CorrectHorse-1!')).toBe(true);
    expect(isStrongPassword('CORRECTHORSE-1!')).toBe(false);
    expect(isStrongPassword('correcthorse-1!')).toBe(false);
    expect(isStrongPassword('CorrectHorse!')).toBe(false);
    expect(isStrongPassword('CorrectHorse1')).toBe(false);
  });

  it('uses the server-provided minimum length', () => {
    const policy = {
      minimum_length: 16,
      requires_uppercase: true,
      requires_lowercase: true,
      requires_digit: true,
      requires_special: true,
    };

    expect(isStrongPassword('CorrectHorse-1!', policy)).toBe(false);
    expect(isStrongPassword('CorrectHorse-1!Long', policy)).toBe(true);
    expect(passwordRequirements('CorrectHorse-1!', policy)[0]).toMatchObject({
      key: 'length',
      passed: false,
    });
  });
});
