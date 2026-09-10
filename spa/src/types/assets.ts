export type AssetCategory = 'machine' | 'mold' | 'vehicle' | 'equipment' | 'furniture' | 'other';
export type AssetStatus = 'active' | 'under_maintenance' | 'disposed';
export type DepreciationMethod = 'straight_line' | 'declining_balance';

export interface Asset {
 id: string;
 asset_code: string;
 name: string;
 description: string | null;
 category: AssetCategory;
 category_label?: string;
 department?: { id: string; name: string; code: string } | null;
 acquisition_date: string;
 acquisition_cost: string;
 useful_life_years: number;
 depreciation_method: DepreciationMethod;
 depreciation_method_label?: string | null;
 salvage_value: string;
 accumulated_depreciation: string;
 monthly_depreciation: string;
 book_value: string;
 status: AssetStatus;
 status_label?: string;
 disposed_date: string | null;
 disposal_amount: string | null;
 disposal_reason: string | null;
 /** AS-03 — non-null only while a disposal request is pending approval. */
 disposal_request: {
  amount: string | null;
  date: string | null;
  reason: string | null;
  requested_by: { id: string; name: string } | null;
  can_cancel: boolean;
 } | null;
 approval_records?: Array<{
  step_order: number;
  role_slug: string;
  action: string;
  remarks: string | null;
  acted_at: string | null;
  approver: { id: string; name: string } | null;
 }>;
 location: string | null;
 insurance_policy_no: string | null;
 insurance_provider: string | null;
 insurance_expiry: string | null;
 insured_value: string | null;
 depreciations?: Array<{
 id: string;
 period_year: number;
 period_month: number;
 depreciation_amount: string;
 accumulated_after: string;
 journal_entry_id: string | null;
 created_at: string | null;
 }>;
 created_at: string | null;
 updated_at: string | null;
}

export interface CreateAssetData {
 name: string;
 description?: string;
 category: AssetCategory;
 department_id?: string | null;
 acquisition_date: string;
 acquisition_cost: string;
 useful_life_years: number;
 depreciation_method?: DepreciationMethod;
 salvage_value?: string;
 location?: string;
 insurance_policy_no?: string;
 insurance_provider?: string;
 insurance_expiry?: string;
 insured_value?: string;
}

export interface UpdateAssetData {
 name?: string;
 description?: string;
 department_id?: string | null;
 useful_life_years?: number;
 salvage_value?: string;
 location?: string;
}

export interface DisposeAssetData {
 disposal_amount: string;
 disposed_date?: string;
 remarks?: string;
}

/* ── Asset Transfers ── */

export type AssetTransferStatus = 'pending' | 'approved' | 'rejected' | 'completed';

export interface AssetTransfer {
 id: string;
 transfer_number: string;
 asset: { id: string; asset_code: string; name: string };
 from_department: { id: string; name: string };
 to_department: { id: string; name: string };
 reason: string | null;
 transfer_date: string;
 status: AssetTransferStatus;
 status_label?: string;
 requested_by: string;
 approved_by: string | null;
 approved_at: string | null;
 created_at: string;
}

export interface CreateTransferData {
 asset_id: string;
 from_department_id: string;
 to_department_id: string;
 reason?: string;
 transfer_date: string;
}
