import { client } from '@/api/client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import type { ContractTemplateSubmission, DiscoveredContractField, PortalContractRealm } from '@/components/contracts/contractTemplateFields';

export interface PortalContractTemplate {
  id: string;
  name: string;
  realm: PortalContractRealm;
  filename: string;
  pdf_fields: DiscoveredContractField[];
  field_mappings: Record<string, string>;
  required_fields: string[];
  version: number;
  auto_fill_available: boolean;
  created_by?: string | null;
  created_at?: string | null;
}

export interface PortalAgreementDocument {
  id: string;
  file_name: string;
  file_size: number;
  uploaded_by?: string | null;
  uploaded_at?: string | null;
  signature_status: 'draft' | 'pending' | 'signed';
  issued_by?: string | null;
  issued_at?: string | null;
  signed_by?: string | null;
  signed_name?: string | null;
  signed_at?: string | null;
  template_version?: number | null;
}

export interface PortalAgreementList {
  data: PortalAgreementDocument[];
  can_sign?: boolean;
  signer_name?: string;
}

function templateFormData(submission: ContractTemplateSubmission): FormData {
  const form = new FormData();
  form.append('name', submission.name);
  form.append('realm', submission.realm);
  form.append('file', submission.file);
  Object.entries(submission.field_mappings).forEach(([pdfField, recordField]) => {
    form.append(`field_mappings[${pdfField}]`, recordField);
  });
  submission.required_fields.forEach((pdfField) => form.append('required_fields[]', pdfField));
  return form;
}

function agreementFormData(file: File): FormData {
  const form = new FormData();
  form.append('file', file);
  return form;
}

export const portalContractsApi = {
  listTemplates: (realm?: PortalContractRealm) =>
    client.get<{ data: PortalContractTemplate[] }>('/b2b/portal-access/contract-templates', { params: realm ? { realm } : undefined }).then((response) => response.data.data),

  inspectTemplate: (file: File, realm: PortalContractRealm) => {
    const form = new FormData();
    form.append('file', file);
    form.append('realm', realm);
    return client.post<ApiSuccess<{ pdf_fields: DiscoveredContractField[]; auto_fill_available: boolean; realm: PortalContractRealm }>>(
      '/b2b/portal-access/contract-templates/inspect', form,
    ).then((response) => response.data.data);
  },

  createTemplate: (submission: ContractTemplateSubmission) =>
    client.post<ApiSuccess<PortalContractTemplate>>('/b2b/portal-access/contract-templates', templateFormData(submission))
      .then((response) => response.data.data),

  updateTemplate: (id: string, data: { field_mappings: Record<string, string>; required_fields: string[] }) =>
    client.put<ApiSuccess<PortalContractTemplate>>(`/b2b/portal-access/contract-templates/${id}`, data).then((response) => response.data.data),

  listCustomerDocuments: (customerId: string) =>
    client.get<{ data: PortalAgreementDocument[] }>(`/crm/customers/${customerId}/agreement-documents`).then((response) => response.data.data),

  attachCustomerPdf: (customerId: string, file: File) =>
    client.post<ApiSuccess<PortalAgreementDocument>>(`/crm/customers/${customerId}/agreement-documents`, agreementFormData(file))
      .then((response) => response.data.data),

  issueCustomerDocument: (customerId: string, documentId: string) =>
    client.post<ApiSuccess<PortalAgreementDocument>>(`/crm/customers/${customerId}/agreement-documents/${documentId}/issue`)
      .then((response) => response.data.data),

  issueCustomerTemplate: (customerId: string, templateId: string) => {
    const form = new FormData();
    form.append('template_id', templateId);
    return client.post<ApiSuccess<PortalAgreementDocument>>(`/crm/customers/${customerId}/agreement-documents`, form)
      .then((response) => response.data.data);
  },

  downloadCustomerDocument: (customerId: string, documentId: string) =>
    client.get<Blob>(`/crm/customers/${customerId}/agreement-documents/${documentId}/download`, { responseType: 'blob' })
      .then((response) => response.data),

  listSupplierDocuments: (vendorId: string) =>
    client.get<{ data: PortalAgreementDocument[] }>(`/b2b/portal-access/suppliers/${vendorId}/agreement-documents`).then((response) => response.data.data),

  attachSupplierPdf: (vendorId: string, file: File) =>
    client.post<ApiSuccess<PortalAgreementDocument>>(`/b2b/portal-access/suppliers/${vendorId}/agreement-documents`, agreementFormData(file))
      .then((response) => response.data.data),

  issueSupplierDocument: (vendorId: string, documentId: string) =>
    client.post<ApiSuccess<PortalAgreementDocument>>(`/b2b/portal-access/suppliers/${vendorId}/agreement-documents/${documentId}/issue`)
      .then((response) => response.data.data),

  issueSupplierTemplate: (vendorId: string, templateId: string) => {
    const form = new FormData();
    form.append('template_id', templateId);
    return client.post<ApiSuccess<PortalAgreementDocument>>(`/b2b/portal-access/suppliers/${vendorId}/agreement-documents`, form)
      .then((response) => response.data.data);
  },

  downloadSupplierDocument: (vendorId: string, documentId: string) =>
    client.get<Blob>(`/b2b/portal-access/suppliers/${vendorId}/agreement-documents/${documentId}/download`, { responseType: 'blob' })
      .then((response) => response.data),

  listPortalDocuments: (realm: PortalContractRealm) =>
    client.get<PortalAgreementList>(`/b2b/${realm === 'customer' ? 'customer' : 'supplier'}/agreement-documents`).then((response) => response.data),

  downloadPortalDocument: (realm: PortalContractRealm, documentId: string) =>
    client.get<Blob>(`/b2b/${realm === 'customer' ? 'customer' : 'supplier'}/agreement-documents/${documentId}/download`, { responseType: 'blob' })
      .then((response) => response.data),

  signPortalDocument: (realm: PortalContractRealm, documentId: string, typedName: string) =>
    client.post<ApiSuccess<PortalAgreementDocument>>(`/b2b/${realm === 'customer' ? 'customer' : 'supplier'}/agreement-documents/${documentId}/sign`, { typed_name: typedName })
      .then((response) => response.data.data),
};

export type PortalAgreementDocumentsPage = PaginatedResponse<PortalAgreementDocument>;
