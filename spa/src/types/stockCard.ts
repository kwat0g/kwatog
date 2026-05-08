/**
 * Series F — Task F3. Stock card types.
 */

export interface StockCardRow {
  id: string;
  date: string | null;
  movement_type: string;
  reference_type: string;
  reference_id: string | null;
  reference_url: string | null;
  in: string;
  out: string;
  unit_cost: string;
  balance: string;
  weighted_avg: string;
  created_by: string | null;
  remarks: string;
}

export interface StockCardSummary {
  balance: string;
  weighted_avg: string;
  value: string;
}

export interface StockCard {
  item: {
    id: string;
    code: string;
    name: string;
    unit_of_measure: string;
  };
  from: string;
  to: string;
  opening: StockCardSummary;
  rows: StockCardRow[];
  closing: StockCardSummary;
}

export interface StockCardResponse {
  data: StockCard;
}

export interface StockCardParams {
  from?: string;
  to?: string;
  location_id?: string;
}
