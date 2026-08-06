import type { ActionCategory, ActionCenterItem } from '@/types/actionCenter';

export function filterActionItems(
 items: ActionCenterItem[],
 category: ActionCategory | 'all',
 search: string,
): ActionCenterItem[] {
 const needle = search.trim().toLocaleLowerCase();

 return items.filter((item) => {
 if (category !== 'all' && item.category !== category) return false;
 if (!needle) return true;

 return [item.title, item.description, item.reference, item.owner_label, item.status_label]
 .filter(Boolean)
 .some((value) => String(value).toLocaleLowerCase().includes(needle));
 });
}
