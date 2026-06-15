export type DriverDeliveryStatus =
  | 'scheduled' | 'loading' | 'in_transit' | 'delivered' | 'confirmed' | 'cancelled';

export interface DriverDelivery {
  id: string;
  delivery_number: string;
  status: DriverDeliveryStatus;
  scheduled_date: string | null;
  departed_at: string | null;
  delivered_at: string | null;
  confirmed_at: string | null;
  sales_order: {
    id: string;
    so_number: string;
    customer?: { id: string; name: string };
  } | null;
  vehicle: { id: string; plate_number: string; name?: string } | null;
  proofs?: Array<{ id: string; proof_type: string; file_name: string; view_url: string }>;
}

export interface DriverPaginated<T> {
  data: T[];
  meta?: {
    total?: number;
    last_page?: number;
    current_page?: number;
    per_page?: number;
    from?: number | null;
    to?: number | null;
    path?: string;
  };
  links?: { first?: string; last?: string; prev?: string | null; next?: string | null };
}
