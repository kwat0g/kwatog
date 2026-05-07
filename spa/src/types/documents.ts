/**
 * Series E (E1/E3) — Document vault types matching the API resource.
 */

export type DocumentType =
  | 'payslip'
  | 'invoice'
  | 'purchase_order'
  | 'purchase_request'
  | 'bill'
  | 'journal_entry'
  | 'coc'
  | 'complaint_8d'
  | 'payroll_register'
  | 'balance_sheet'
  | 'income_statement'
  | 'trial_balance'
  | 'sss_r3'
  | 'philhealth_rf1'
  | 'pagibig_remittance'
  | 'bir_1601c'
  | 'bir_2316'
  | 'ncr'
  | 'work_order_traveler'
  | 'bulk_pdf';

export interface DocumentRecord {
  id: string;
  document_type: DocumentType;
  document_label: string;
  file_name: string;
  file_size: number;
  mime_type: string;
  is_confidential: boolean;
  generated_at: string;
  generated_by: { id: string; name: string } | null;
  view_url: string;
  download_url: string;
}
