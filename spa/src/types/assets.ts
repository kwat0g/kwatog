export type AssetCategory = 'machine' | 'mold' | 'vehicle' | 'equipment' | 'furniture' | 'other';
export type AssetStatus = 'active' | 'under_maintenance' | 'disposed';

export interface Asset {
  id: string;
  asset_code: string;
  name: string;
  description: string | null;
  category: AssetCategory;
  department?: { id: string; name: string; code: string } | null;
  acquisition_date: string;
  acquisition_cost: string;
  useful_life_years: number;
  salvage_value: string;
  accumulated_depreciation: string;
  monthly_depreciation: string;
  book_value: string;
  status: AssetStatus;
  disposed_date: string | null;
  disposal_amount: string | null;
  location: string | null;
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
  department_id?: number | null;
  acquisition_date: string;
  acquisition_cost: string;
  useful_life_years: number;
  salvage_value?: string;
  location?: string;
}

export interface DisposeAssetData {
  disposal_amount: string;
  disposed_date?: string;
  remarks?: string;
}
