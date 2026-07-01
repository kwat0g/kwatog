// Task 16 — Document Control types

export interface DocumentRevision {
  id: string;
  revision_number: number;
  effective_date: string | null;
  published_at: string | null;
}

export interface ControlledDocument {
  id: string;
  code: string;
  title: string;
  category: string;
  description: string | null;
  assignee_role: string | null;
  review_interval_months: number | null;
  last_reviewed_at: string | null;
  is_active: boolean;
  current_revision: DocumentRevision | null;
  created_at: string;
  updated_at: string;
}

export type DocumentCategory = 'sop' | 'work_instruction' | 'form' | 'policy' | 'specification';

export interface CreateDocumentData {
  code: string;
  title: string;
  category: DocumentCategory;
  description?: string;
  assignee_role?: string;
  review_interval_months?: number;
}
