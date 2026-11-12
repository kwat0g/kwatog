export const WARRANTY_ADDITIONAL_TERMS_MAX_LENGTH = 2000;

const OTHER_COVERED_ISSUE_PREFIX = 'Other covered issue: ';

export function composeWarrantyAdditionalTerms(
  additionalTerms: string | null | undefined,
  otherCoveredIssue: string | null | undefined,
): string | null {
  const issue = otherCoveredIssue?.trim();
  const terms = additionalTerms?.trim();
  const composed = [
    issue ? `${OTHER_COVERED_ISSUE_PREFIX}${issue}` : null,
    terms || null,
  ].filter((value): value is string => value !== null);

  return composed.length ? composed.join('\n') : null;
}

export function warrantyAdditionalTermsCharacterLimit(otherCoveredIssue: string | null | undefined): number {
  const issue = otherCoveredIssue?.trim();
  const issueLineLength = issue ? OTHER_COVERED_ISSUE_PREFIX.length + issue.length + 1 : 0;
  return Math.max(0, WARRANTY_ADDITIONAL_TERMS_MAX_LENGTH - issueLineLength);
}
