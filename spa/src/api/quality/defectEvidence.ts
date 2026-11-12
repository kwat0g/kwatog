import { client } from '../client';

export interface InspectionDefectEvidence {
  id: string;
  category: string;
  category_label: string;
  severity: 'minor' | 'major' | 'critical';
  severity_label: string;
  affected_quantity: string;
  notes: string | null;
  photo_url: string;
  grn_item: { id: string; item_code: string | null; item_name: string | null } | null;
  created_by: { id: string; name: string } | null;
  created_at: string | null;
}

export interface CreateInspectionDefectEvidence {
  category: string;
  other_category?: string;
  severity: 'minor' | 'major' | 'critical';
  affected_quantity: number;
  notes?: string;
  photo: File;
}

export const defectEvidenceApi = {
  list: (inspectionId: string) =>
    client.get<{ data: InspectionDefectEvidence[] }>(`/quality/inspections/${inspectionId}/defect-evidence`)
      .then((response) => response.data.data),
  create: (inspectionId: string, data: CreateInspectionDefectEvidence) => {
    const form = new FormData();
    form.append('category', data.category);
    if (data.other_category) form.append('other_category', data.other_category);
    form.append('severity', data.severity);
    form.append('affected_quantity', String(data.affected_quantity));
    if (data.notes) form.append('notes', data.notes);
    form.append('photo', data.photo);
    return client.post<{ data: InspectionDefectEvidence }>(`/quality/inspections/${inspectionId}/defect-evidence`, form)
      .then((response) => response.data.data);
  },
};
