import { describe, expect, it } from 'vitest';
import { notificationLinkTo } from './notificationLink';

describe('notificationLinkTo', () => {
 it('keeps same-app links within the requested portal scope', () => {
  expect(notificationLinkTo('/portal/customer/orders/42?tab=delivery#history', '/portal/customer'))
   .toBe('/portal/customer/orders/42?tab=delivery#history');
  expect(notificationLinkTo('/crm/orders/42', '/portal/customer')).toBeNull();
  expect(notificationLinkTo('/portal/customer/orders/42')).toBeNull();
 });

 it('rejects external and malformed destinations', () => {
  expect(notificationLinkTo('//example.com/orders/42')).toBeNull();
  expect(notificationLinkTo('/\\\\example.com/orders/42')).toBeNull();
  expect(notificationLinkTo('https://example.com/orders/42')).toBeNull();
 });
});
