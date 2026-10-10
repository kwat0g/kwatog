import { test, expect } from '@playwright/test';
import { baseUrl, loginAs, statefulHeaders } from './helpers';

test('admin receives valid option data from each real API catalog', async ({ page }) => {
  await loginAs(page, 'admin@ogami.test', 'password');
  const endpoints = [
    {
      path: '/api/v1/attendance/overtime-requests/options',
      expected: { data: expect.objectContaining({
        statuses: expect.arrayContaining([expect.objectContaining({ value: 'pending', label: expect.any(String) })]),
        minimum_hours: expect.any(Number),
        maximum_hours: expect.any(Number),
        request_min_hours: expect.any(Number),
        request_future_days: expect.any(Number),
        request_past_days: expect.any(Number),
        premium_multiplier: expect.any(Number),
      }) },
    },
    {
      path: '/api/v1/payroll-periods/options',
      expected: { data: expect.objectContaining({
        statuses: expect.arrayContaining([expect.objectContaining({ value: 'draft', label: expect.any(String) })]),
        period_types: expect.any(Array),
        half_types: expect.any(Array),
        employment_types: expect.any(Array),
        pay_types: expect.any(Array),
        departments: expect.any(Array),
        bank_file_formats: expect.any(Array),
        default_bank_file_format: expect.any(String),
      }) },
    },
    {
      path: '/api/v1/payroll-periods/bank-file/options',
      expected: { data: expect.objectContaining({
        formats: expect.arrayContaining([expect.objectContaining({ value: 'generic', label: expect.any(String) })]),
        default_format: expect.any(String),
      }) },
    },
    {
      path: '/api/v1/hr/employees/options',
      expected: { data: expect.objectContaining({
        statuses: expect.arrayContaining([expect.objectContaining({ value: 'active', label: expect.any(String) })]),
        employment_types: expect.any(Array),
        pay_types: expect.any(Array),
        genders: expect.any(Array),
        civil_statuses: expect.any(Array),
        separation_reasons: expect.any(Array),
        skill_levels: expect.any(Array),
      }) },
    },
    {
      path: '/api/v1/hr/employees/status-counts',
      expected: { data: expect.objectContaining({
        counts: expect.objectContaining({ active: expect.any(Number) }),
        total: expect.any(Number),
      }) },
    },
    {
      path: '/api/v1/hr/departments/tree',
      expected: { data: expect.arrayContaining([expect.objectContaining({
        id: expect.any(String),
        name: expect.any(String),
        code: expect.any(String),
        is_active: expect.any(Boolean),
      })]) },
    },
    {
      path: '/api/v1/leaves/requests/options',
      expected: { data: expect.objectContaining({
        statuses: expect.arrayContaining([expect.objectContaining({ value: 'pending_dept', label: expect.any(String) })]),
        half_day_periods: expect.any(Array),
        holiday_dates: expect.any(Array),
      }) },
    },
    {
      path: '/api/v1/inventory/items/options',
      expected: { data: expect.objectContaining({
        item_types: expect.arrayContaining([expect.objectContaining({ value: 'raw_material', label: expect.any(String) })]),
        replenishment_basis: expect.objectContaining({
          demand_history_days: expect.any(Number),
          coverage_buffer_ratio: expect.any(Number),
          default_lead_time_days: expect.any(Number),
        }),
        reorder_methods: expect.any(Array),
        adjustment_directions: expect.any(Array),
        stock_statuses: expect.any(Array),
      }) },
    },
    {
      path: '/api/v1/crm/sales-orders/options',
      expected: { data: expect.objectContaining({
        statuses: expect.arrayContaining([expect.objectContaining({
          value: 'confirmed',
          label: expect.any(String),
          next_statuses: expect.arrayContaining([expect.objectContaining({ value: 'in_production', label: expect.any(String) })]),
        })]),
      }) },
    },
    {
      path: '/api/v1/invoices/options',
      expected: { data: expect.objectContaining({
        statuses: expect.arrayContaining([expect.objectContaining({ value: 'draft', label: expect.any(String) })]),
      }) },
    },
    {
      path: '/api/v1/accounting/options',
      expected: { data: expect.objectContaining({
        payment_methods: expect.arrayContaining([expect.objectContaining({ value: 'cash', label: expect.any(String) })]),
        account_types: expect.arrayContaining([expect.objectContaining({
          value: 'asset',
          label: expect.any(String),
          default_normal_balance: expect.any(String),
        })]),
        normal_balances: expect.any(Array),
        credit_note_types: expect.any(Array),
        credit_note_statuses: expect.any(Array),
      }) },
    },
  ];

  for (const { path, expected } of endpoints) {
    const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
    expect(response.status(), `${path} status`).toBe(200);
    expect(await response.json(), `${path} response`).toEqual(expect.objectContaining(expected));
  }
});
