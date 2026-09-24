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
 association?: { type: 'machine' | 'mold' | 'vehicle'; id: string; code: string; name: string } | null;
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
   can_approve: boolean;
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

export interface AssociateAssetData {
 target_type: 'machine' | 'mold' | 'vehicle';
 target_id: string | null;
}

export interface DisposeAssetData {
 disposal_amount: string;
 disposed_date?: string;
 remarks?: string;
}
