/**
 * Compact browser paths for the authenticated SPA. More specific prefixes
 * must stay ahead of their module fallback.
 */
export const APP_PATH_ALIASES = [
  ['/portal/customer', '/pc'],
  ['/portal/supplier', '/ps'],
  ['/portal/password-reset', '/pr'],
  ['/hr/attendance/overtime', '/ot'],
  ['/hr/attendance', '/at'],
  ['/hr/employee-trainings', '/et'],
  ['/hr/profile-update-requests', '/pru'],
  ['/hr/recruitment', '/rec'],
  ['/hr/separations', '/sep'],
  ['/hr/departments', '/dep'],
  ['/hr/positions', '/pos'],
  ['/hr/employees', '/emp'],
  ['/hr/leaves', '/lv'],
  ['/hr/loans', '/ln'],
  ['/hr/training', '/tm'],
  ['/hr/trainings', '/tr'],
  ['/hr/skills', '/sk'],
  ['/crm/inquiries', '/inq'],
  ['/crm/complaints', '/co'],
  ['/crm/customers', '/cu'],
  ['/crm/price-agreements', '/pa'],
  ['/crm/sales-orders', '/so'],
  ['/crm/products', '/sku'],
  ['/portal', '/pt'],
  ['/hr', '/h'],
  ['/crm', '/c'],
  ['/accounting', '/ac'],
  ['/action-center', '/acn'],
  ['/admin', '/ad'],
  ['/alerts', '/al'],
  ['/approvals', '/ap'],
  ['/assets', '/as'],
  ['/budgeting', '/b'],
  ['/calendar', '/cal'],
  ['/chains', '/ch'],
  ['/dashboard', '/d'],
  ['/decision-center', '/dc'],
  ['/driver', '/dr'],
  ['/factory', '/f'],
  ['/forecasting', '/fc'],
  ['/inventory', '/i'],
  ['/maintenance/mobile', '/mm'],
  ['/maintenance', '/m'],
  ['/mrp', '/mr'],
  ['/notifications', '/n'],
  ['/payroll', '/py'],
  ['/production', '/p'],
  ['/purchasing', '/pu'],
  ['/quality', '/q'],
  ['/recovery', '/rc'],
  ['/return-management', '/ret'],
  ['/self-service', '/s'],
  ['/supply-chain', '/sc'],
] as const;

const LONG_TO_SHORT = [...APP_PATH_ALIASES].sort(([a], [b]) => b.length - a.length);
const SHORT_TO_LONG = [...APP_PATH_ALIASES].sort(([, a], [, b]) => b.length - a.length);

function replacePrefix(pathname: string, from: string, to: string): string | null {
  if (pathname === from) return to;
  if (pathname.startsWith(`${from}/`)) return `${to}${pathname.slice(from.length)}`;
  return null;
}

export function shortenAppPath(pathname: string): string {
  for (const [longPrefix, shortPrefix] of LONG_TO_SHORT) {
    const shortened = replacePrefix(pathname, longPrefix, shortPrefix);
    if (shortened !== null) return shortened;
  }
  return pathname;
}

/** Resolve a compact path to its original route name for legacy path checks. */
export function expandAppPath(pathname: string): string {
  for (const [longPrefix, shortPrefix] of SHORT_TO_LONG) {
    const expanded = replacePrefix(pathname, shortPrefix, longPrefix);
    if (expanded !== null) return expanded;
  }
  return pathname;
}
