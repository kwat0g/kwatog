export interface InvoicePaymentSubmission {
  id: string;
  status: 'pending' | 'verified' | 'rejected';
  amount: string;
  sending_bank: string;
  payment_date: string;
  reference_number: string;
  proof_file_name: string;
  proof_mime: string;
  proof_size: number;
  submitted_at: string;
  reviewed_at: string | null;
  rejection_reason: string | null;
  collection_id: string | null;
}
