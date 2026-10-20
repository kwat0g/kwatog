import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import SelfServicePayslipDetailPage from './payslip-detail';
import { downloadAuthenticatedFile } from '@/api/download';

vi.mock('@/api/download', () => ({ downloadAuthenticatedFile: vi.fn().mockResolvedValue(true) }));

beforeEach(() => vi.clearAllMocks());

it('waits for an explicit click before requesting the authenticated payslip download', async () => {
 render(
  <MemoryRouter initialEntries={['/self-service/payslips/payroll-hash-1']}>
   <Routes>
    <Route path="/self-service/payslips/:id" element={<SelfServicePayslipDetailPage />} />
   </Routes>
  </MemoryRouter>,
 );

 expect(downloadAuthenticatedFile).not.toHaveBeenCalled();
 fireEvent.click(screen.getByRole('button', { name: 'Download payslip PDF' }));

 expect(downloadAuthenticatedFile).toHaveBeenCalledWith(
  '/api/v1/hr/self-service/payslips/payroll-hash-1/download',
  expect.objectContaining({ errorMessage: expect.any(String) }),
 );
});
