import { addDays, format, parseISO } from 'date-fns';

function addIsoDays(value: string, days: number): string {
  return format(addDays(parseISO(value), days), 'yyyy-MM-dd');
}

export function nextReplacementStartDate(sourceStart: string | undefined, today: string): string {
  const latestStart = sourceStart && sourceStart > today ? sourceStart : today;
  return addIsoDays(latestStart, 1);
}

export function replacementEndDate(sourceEnd: string | undefined, replacementStart: string): string {
  return sourceEnd && sourceEnd >= replacementStart ? sourceEnd : '';
}
